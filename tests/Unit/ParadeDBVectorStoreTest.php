<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Tests\Unit;

use InvalidArgumentException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Semhoun\NeuronParadeDB\VectorStore\HybridVectorStoreInterface;
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBVectorStore;

final class ParadeDBVectorStoreTest extends TestCase
{
    public function testRequestLimitsFiltersAndCollectionArePerCall(): void
    {
        $sql = [];
        $bindings = [];
        $check = $this->createStub(PDOStatement::class);
        $check->method('execute')->willReturn(true);
        $check->method('fetch')->willReturn(false);
        $result = $this->createMock(PDOStatement::class);
        $result->expects(self::exactly(4))->method('execute')->willReturnCallback(static function (array $params) use (&$bindings): bool {
            $bindings[] = $params;
            return true;
        });
        $result->method('fetch')->willReturn(false);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(5))->method('prepare')->willReturnCallback(static function (string $query) use ($check, $result, &$sql): PDOStatement {
            if (str_contains($query, 'pg_catalog')) {
                return $check;
            }
            $sql[] = $query;
            return $result;
        });
        $store = new ParadeDBVectorStore($pdo, 3, topK: 7, collectionId: 'A');
        $filter = Filter::where('sourceType', 'web')->whereAny(Filter::eq('sourceName', 'one'), Filter::eq('sourceName', 'two'));
        $store->search(new SearchRequest([1.0, 0.0, 0.0], $filter, 1));
        $store->hybridSearch('query', [1.0, 0.0, 0.0], $filter, 2);
        $store->search(new SearchRequest([1.0, 0.0, 0.0]));
        $store->delete($filter);
        self::assertStringContainsString('LIMIT 1', $sql[0]);
        self::assertStringContainsString('collection_id = :collection_id AND (', $sql[0]);
        self::assertStringContainsString('collection_id = :vector_collection_id AND (', $sql[1]);
        self::assertStringContainsString('collection_id = :bm25_collection_id AND (', $sql[1]);
        self::assertSame(2, substr_count($sql[1], 'LIMIT 4'));
        self::assertStringEndsWith('LIMIT 2', $sql[1]);
        self::assertStringContainsString('LIMIT 7', $sql[2]);
        self::assertStringContainsString('AND TRUE', $sql[2]);
        self::assertStringContainsString('collection_id = :collection_id AND (', $sql[3]);
        self::assertSame('A', $bindings[0][':collection_id']);
        self::assertSame('A', $bindings[1][':vector_collection_id']);
        self::assertSame('A', $bindings[1][':bm25_collection_id']);
        self::assertSame('web', $bindings[1][':vf0']);
        self::assertSame('web', $bindings[1][':bf0']);
        self::assertCount(2, $bindings[2]);
    }

    public function testInvalidDocumentsAreRejectedBeforeStartingBatch(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required()->filterable());
        foreach ([null, [], ['tenant' => null], ['tenant' => 1], ['tenant' => 'A', 'extra' => new \stdClass()]] as $metadata) {
            $pdo = $this->pdoWithMissingTable();
            $pdo->expects(self::never())->method('beginTransaction');
            $store = new ParadeDBVectorStore($pdo, 3, schema: $schema);
            self::assertSame($schema, $store->getSchema());
            $good = (new Document('good'))->setMetadata(['tenant' => 'A'])->setEmbedding([1, 0, 0]);
            $bad = (new Document('bad'))->setMetadata($metadata ?? ['tenant' => 'A']);
            if ($metadata !== null) {
                $bad->setEmbedding([1, 0, 0]);
            }
            try {
                $store->addDocuments([$good, $bad]);
                self::fail('The entire batch must be validated before any write.');
            } catch (DocumentSchemaException|VectorStoreException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testInvalidRequestsAndFiltersAreRejectedBeforeSql(): void
    {
        foreach ([[1.0], [0.0, 0.0, 0.0], [INF, 0.0, 0.0], [NAN, 0.0, 0.0], [1 => 1.0, 2 => 0.0, 3 => 0.0]] as $embedding) {
            $store = new ParadeDBVectorStore($this->pdoWithMissingTable(), 3);
            try {
                $store->search(new SearchRequest($embedding));
                self::fail('Invalid vectors must be rejected before SQL.');
            } catch (VectorStoreException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        foreach (['search', 'hybrid', 'delete', 'topK'] as $operation) {
            $store = new ParadeDBVectorStore($this->pdoWithMissingTable(), 3);
            try {
                match ($operation) {
                    'search' => $store->search(new SearchRequest([1, 0, 0], Filter::eq('unknown', 'x'))),
                    'hybrid' => $store->hybridSearch('query', [1, 0, 0], Filter::eq('unknown', 'x')),
                    'delete' => $store->delete(Filter::eq('unknown', 'x')),
                    'topK' => $store->hybridSearch('query', [1, 0, 0], topK: 0),
                };
                self::fail('Invalid requests must not prepare SQL.');
            } catch (DocumentSchemaException|VectorStoreException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testDefaultConstructorDoesNotCreateDatabaseObjects(): void
    {
        $pdo = $this->pdoWithMissingTable();
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::once())->method('setAttribute')
            ->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        self::assertInstanceOf(HybridVectorStoreInterface::class, new ParadeDBVectorStore($pdo));
    }

    public function testRejectsNonPositiveConfiguration(): void
    {
        foreach ([[0, 4, 60], [-1, 4, 60], [3, 0, 60], [3, -1, 60], [3, 4, 0], [3, 4, -1]] as [$dimensions, $topK, $rrfK]) {
            try {
                new ParadeDBVectorStore($this->createStub(PDO::class), $dimensions, 'documents', $topK, $rrfK);
                self::fail('Non-positive dimensions, topK or rrfK must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testCollectionClonesPreserveConfigurationWithoutSqlOrConnectionChanges(): void
    {
        $pdo = $this->pdoWithMissingTable();
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::once())->method('setAttribute')->willReturn(true);
        $store = new ParadeDBVectorStore($pdo, 3, 'custom_documents', 7, 11);
        $reflection = new ReflectionObject($store);
        foreach (['A', 'B', '0', '00', ' ', " O'Reilly\\; -- ", null] as $collection) {
            $clone = $store->forCollection('intermediate')->forCollection($collection);
            self::assertNotSame($store, $clone);
            self::assertSame($store::class, $clone::class);
            foreach ($reflection->getProperties() as $property) {
                if ($property->getName() === 'collectionId') {
                    self::assertNull($property->getValue($store));
                    self::assertSame($collection, $property->getValue($clone));
                } else {
                    self::assertSame($property->getValue($store), $property->getValue($clone));
                }
            }
        }
    }

    public function testConstructorAcceptsCollectionAsLastOptionalArgument(): void
    {
        $pdo = $this->pdoWithMissingTable();
        $pdo->expects(self::never())->method('exec');
        $store = new ParadeDBVectorStore($pdo, 3, 'documents', 2, 60, '0');
        $property = (new ReflectionObject($store))->getProperty('collectionId');
        self::assertSame('0', $property->getValue($store));
    }

    public function testConstructorRejectsEmptyCollectionBeforeSql(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');
        $pdo->expects(self::never())->method('exec');
        $this->expectException(InvalidArgumentException::class);
        new ParadeDBVectorStore($pdo, collectionId: '');
    }

    public function testCloneRejectsEmptyCollectionWithoutSql(): void
    {
        $pdo = $this->pdoWithMissingTable();
        $pdo->expects(self::never())->method('exec');
        $store = new ParadeDBVectorStore($pdo, collectionId: 'A');
        $this->expectException(InvalidArgumentException::class);
        $store->forCollection('');
    }

    public function testRejectsUnsafeTableIdentifiers(): void
    {
        foreach (['', '1documents', 'public.documents', 'documents; DROP TABLE users', 'has space', 'has-dash', '"quoted"', str_repeat('a', 64)] as $table) {
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::never())->method('exec');
            try {
                new ParadeDBVectorStore($pdo, 3, $table);
                self::fail('Unsafe SQL identifier was accepted: '.$table);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testEmptyBatchIsNoOp(): void
    {
        $pdo = $this->pdoWithMissingTable();
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $store = new ParadeDBVectorStore($pdo, 3);
        self::assertSame($store, $store->addDocuments([]));
    }

    public function testRejectsEmptyAndUnknownArrayDeleteFiltersBeforeSql(): void
    {
        foreach ([[], ['unknown' => 'value'], ['source_type' => 'web', 'unknown' => 'value'], ['collection_id' => 'B'], ['source_type' => 'web', 'collection_id' => '']] as $filter) {
            $pdo = $this->pdoWithMissingTable();
            $store = new ParadeDBVectorStore($pdo, 3, collectionId: 'A');
            try {
                $store->deleteBy($filter);
                self::fail('Empty or unknown filters must not execute a deletion.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testUpgradeRejectsInvalidConfigurationBeforeSql(): void
    {
        foreach ([[0, 'documents'], [-1, 'documents'], [3, ''], [3, 'public.documents'],
            [3, 'documents; DROP TABLE users'], [3, str_repeat('a', 49)]] as [$dimensions, $table]) {
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::never())->method('prepare');
            $pdo->expects(self::never())->method('exec');
            $pdo->expects(self::never())->method('beginTransaction');
            try {
                ParadeDBVectorStore::upgradeToV11($pdo, $dimensions, $table);
                self::fail('Invalid upgrade configuration must be rejected before SQL.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function pdoWithMissingTable(): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('fetch')->willReturn(false);
        $pdo = $this->createMock(PDO::class);
        // Only the constructor's read-only schema check may prepare SQL.
        $pdo->expects(self::once())->method('prepare')
            ->with(self::stringContains('pg_catalog.to_regclass'))
            ->willReturn($statement);

        return $pdo;
    }
}
