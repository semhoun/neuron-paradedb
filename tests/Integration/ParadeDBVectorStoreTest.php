<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Tests\Integration;

use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBVectorStore;

final class ParadeDBVectorStoreTest extends TestCase
{
    private PDO $pdo;
    private ParadeDBVectorStore $store;
    private string $table;

    protected function setUp(): void
    {
        $dsn = getenv('PARADEDB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('PARADEDB_DSN is not configured.');
        }

        $this->pdo = new PDO($dsn, getenv('PARADEDB_USER') ?: 'postgres', getenv('PARADEDB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->table = 'test_paradedb_'.bin2hex(random_bytes(8));
        $this->store = new ParadeDBVectorStore($this->pdo, 3, $this->table, 2, 60);
        $this->store->setupDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->store)) {
            $this->store->dropTable();
        }
    }

    public function testSetupIsIdempotentAndPreservesDocuments(): void
    {
        $this->store->addDocument($this->document('retained', [1.0, 0.0, 0.0]));
        $this->store->setupDatabase();
        self::assertSame(['retained'], $this->storedIds());
    }

    public function testRejectsExistingTableWithDifferentDimensions(): void
    {
        $this->expectException(VectorStoreException::class);
        (new ParadeDBVectorStore($this->pdo, 4, $this->table))->setupDatabase();
    }

    public function testDropTableIsIdempotent(): void
    {
        $this->store->dropTable();
        $this->store->dropTable();
        $statement = $this->pdo->prepare('SELECT to_regclass(:table)');
        $statement->execute(['table' => $this->table]);
        self::assertNull($statement->fetchColumn());
    }

    public function testUpsertFullyHydratesBothSearchModes(): void
    {
        $document = $this->document("id'\\special", [0.0, 1.0, 0.0]);
        $this->store->addDocument($document);
        $document->content = "uniquetoken O'Reilly \\ path \"quoted\"\nsecond line";
        $document->sourceType = "web' OR '1'='1";
        $document->sourceName = "name'; DROP TABLE example; --";
        $document->embedding = [1.0, 0.0, 0.0];
        $document->metadata = ['nested' => ['quote' => "O'Reilly", 'enabled' => true, 'nothing' => null], 'list' => [1, 2]];
        self::assertSame($this->store, $this->store->addDocument($document));
        self::assertSame([(string) $document->id], $this->storedIds());

        foreach ([$this->store->similaritySearch([1.0, 0.0, 0.0]), $this->store->hybridSearch('uniquetoken', [1.0, 0.0, 0.0])] as $results) {
            $results = $this->documents($results);
            self::assertCount(1, $results);
            self::assertSame($document->id, $results[0]->getId());
            self::assertSame($document->content, $results[0]->getContent());
            self::assertSame($document->sourceType, $results[0]->getSourceType());
            self::assertSame($document->sourceName, $results[0]->getSourceName());
            self::assertEquals($document->metadata, $results[0]->metadata);
            self::assertEquals($document->embedding, $results[0]->getEmbedding());
        }
    }

    public function testSqlFailureRollsBackEntireBatchIncludingUpsert(): void
    {
        $existing = $this->document('existing', [1.0, 0.0, 0.0]);
        $this->store->addDocument($existing);
        $this->pdo->exec("ALTER TABLE {$this->table} ADD CONSTRAINT reject_bad CHECK (content <> 'rejectme')");
        $existing->content = 'changed';
        $bad = $this->document('bad', [0.0, 1.0, 0.0]);
        $bad->content = 'rejectme';
        try {
            $this->store->addDocuments([$existing, $this->document('new', [1.0, 0.0, 0.0]), $bad]);
            self::fail('The database constraint must reject the last document.');
        } catch (PDOException $exception) {
            self::assertSame('23514', (string) $exception->getCode());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(['existing'], $this->storedIds());
        self::assertSame('existing', $this->documents($this->store->similaritySearch([1.0, 0.0, 0.0]))[0]->content);
    }

    public function testJsonFailureRollsBackEntireBatch(): void
    {
        $bad = $this->document('bad', [0.0, 1.0, 0.0]);
        $bad->metadata = ['invalid_utf8' => "\xB1\x31"];
        try {
            $this->store->addDocuments([$this->document('good', [1.0, 0.0, 0.0]), $bad]);
            self::fail('Invalid UTF-8 metadata must fail JSON encoding.');
        } catch (JsonException $exception) {
            self::assertSame(JSON_ERROR_UTF8, $exception->getCode());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([], $this->storedIds());
    }

    public function testSuccessfulBatchDoesNotCommitExistingTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->store->addDocuments([$this->document('first', [1.0, 0.0, 0.0]), $this->document('second', [0.0, 1.0, 0.0])]);
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(['first', 'second'], $this->storedIds());
        $this->pdo->rollBack();
        self::assertSame([], $this->storedIds());
    }

    public function testJsonFailurePreservesExistingTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->store->addDocument($this->document('outer', [1.0, 0.0, 0.0]));
        $bad = $this->document('bad', [0.0, 1.0, 0.0]);
        $bad->metadata = ['invalid_utf8' => "\xB1\x31"];
        try {
            $this->store->addDocuments([$this->document('inner', [1.0, 0.0, 0.0]), $bad]);
            self::fail('Invalid JSON must reject the batch.');
        } catch (JsonException $exception) {
            self::assertSame(JSON_ERROR_UTF8, $exception->getCode());
        }
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(['outer'], $this->storedIds());
        $this->pdo->commit();
        self::assertSame(['outer'], $this->storedIds());
    }

    public function testSqlFailureUsesSavepointAndLeavesOuterTransactionUsable(): void
    {
        $this->pdo->exec("ALTER TABLE {$this->table} ADD CONSTRAINT reject_bad CHECK (content <> 'rejectme')");
        $this->pdo->beginTransaction();
        $this->store->addDocument($this->document('outer', [1.0, 0.0, 0.0]));
        $bad = $this->document('bad', [0.0, 1.0, 0.0]);
        $bad->content = 'rejectme';
        try {
            $this->store->addDocuments([$this->document('inner', [1.0, 0.0, 0.0]), $bad]);
            self::fail('The database constraint must reject the batch.');
        } catch (PDOException $exception) {
            self::assertSame('23514', (string) $exception->getCode());
        }
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(['outer'], $this->storedIds());
        $this->store->addDocument($this->document('after', [1.0, 0.0, 0.0]));
        $this->pdo->commit();
        self::assertSame(['after', 'outer'], $this->storedIds());
    }

    public function testVectorSearchUsesCosineScoresAndTopK(): void
    {
        $this->store->addDocuments([
            $this->document('nearest', [1.0, 0.0, 0.0]),
            $this->document('middle', [0.8, 0.6, 0.0]),
            $this->document('farthest', [0.0, 1.0, 0.0]),
        ]);
        $results = $this->documents($this->store->similaritySearch([1.0, 0.0, 0.0]));
        self::assertCount(2, $results);
        self::assertSame('nearest', $results[0]->id);
        self::assertSame('middle', $results[1]->id);
        self::assertEqualsWithDelta(1.0, $results[0]->score, 0.000001);
        self::assertEqualsWithDelta(0.8, $results[1]->score, 0.000001);
    }

    public function testHybridSearchFusesExactRanksAndRespectsLimitAndRrfK(): void
    {
        $this->store->addDocuments([
            $this->document('semantic', [1.0, 0.0, 0.0]),
            $this->document('uniquetoken', [0.8, 0.6, 0.0]),
            $this->document('other', [0.0, 1.0, 0.0]),
        ]);
        foreach ([60, 10] as $rrfK) {
            $store = new ParadeDBVectorStore($this->pdo, 3, $this->table, 2, $rrfK);
            $results = $this->documents($store->hybridSearch('uniquetoken', [1.0, 0.0, 0.0]));
            self::assertCount(2, $results);
            self::assertSame('uniquetoken', $results[0]->id);
            self::assertSame('semantic', $results[1]->id);
            self::assertEqualsWithDelta(1 / ($rrfK + 2) + 1 / ($rrfK + 1), $results[0]->score, 0.00000001);
            self::assertEqualsWithDelta(1 / ($rrfK + 1), $results[1]->score, 0.00000001);
        }
    }

    public function testHybridSearchWithoutLexicalMatchesUsesVectorRanks(): void
    {
        $this->store->addDocuments([
            $this->document('nearest', [1.0, 0.0, 0.0]),
            $this->document('middle', [0.8, 0.6, 0.0]),
            $this->document('far', [0.0, 1.0, 0.0]),
        ]);
        $results = $this->documents($this->store->hybridSearch('nonexistentlexeme', [1.0, 0.0, 0.0]));
        self::assertCount(2, $results);
        self::assertSame('nearest', $results[0]->id);
        self::assertSame('middle', $results[1]->id);
        self::assertEqualsWithDelta(1 / 61, $results[0]->score, 0.00000001);
        self::assertEqualsWithDelta(1 / 62, $results[1]->score, 0.00000001);
    }

    public function testDeleteSupportsNativeArrayAndDeprecatedForms(): void
    {
        foreach ([
            ['a', 'web', 'shared'], ['b', 'web', 'other'], ['c', 'file', 'shared'],
            ['d', 'file', 'other'], ['e', 'legacy', 'old'], ['f', 'array', 'one'], ['g', 'array', 'two'],
        ] as [$id, $type, $name]) {
            $this->store->addDocument($this->document($id, [1.0, 0.0, 0.0], $type, $name));
        }
        self::assertSame($this->store, $this->store->deleteBy('web', 'shared'));
        self::assertSame(['b', 'c', 'd', 'e', 'f', 'g'], $this->storedIds());
        $this->store->deleteBy('web');
        self::assertSame(['c', 'd', 'e', 'f', 'g'], $this->storedIds());
        $this->store->deleteBy(['source_name' => 'shared']);
        self::assertSame(['d', 'e', 'f', 'g'], $this->storedIds());
        $this->store->deleteBy(['source_type' => 'array', 'source_name' => 'one']);
        self::assertSame(['d', 'e', 'g'], $this->storedIds());
        $this->store->deleteBy(['source_type' => 'array']);
        self::assertSame(['d', 'e'], $this->storedIds());
        self::assertSame($this->store, $this->store->deleteBySource('legacy', 'old'));
        self::assertSame(['d'], $this->storedIds());
    }

    public function testDeleteTreatsSpecialCharactersAsLiteralValues(): void
    {
        $type = "web' OR 1=1 --";
        $name = "path\\O'Reilly";
        $this->store->addDocuments([
            $this->document('target', [1.0, 0.0, 0.0], $type, $name),
            $this->document('keep', [0.0, 1.0, 0.0], 'web', $name),
        ]);
        $this->store->deleteBy(['source_type' => $type, 'source_name' => $name]);
        self::assertSame(['keep'], $this->storedIds());
        $this->store->deleteBy('web', "' OR 1=1 --");
        self::assertSame(['keep'], $this->storedIds());
    }

    public function testMissingEmbeddingIsRejected(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->store->addDocument(new Document('missing embedding'));
    }

    public function testHybridQuerySpecialCharactersCannotAlterStoredData(): void
    {
        $this->store->addDocument($this->document('retained', [1.0, 0.0, 0.0]));
        $results = $this->documents($this->store->hybridSearch("O'Reilly'; DROP TABLE {$this->table}; --", [1.0, 0.0, 0.0]));
        self::assertCount(1, $results);
        self::assertSame('retained', $results[0]->id);
        self::assertSame(['retained'], $this->storedIds());
    }

    /** @param float[] $embedding */
    private function document(string $id, array $embedding, string $sourceType = 'manual', string $sourceName = 'manual'): Document
    {
        $document = new Document($id);
        $document->id = $id;
        $document->embedding = $embedding;
        $document->sourceType = $sourceType;
        $document->sourceName = $sourceName;
        return $document;
    }

    /** @return list<string> */
    private function storedIds(): array
    {
        return $this->pdo->query("SELECT id FROM {$this->table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param iterable<Document> $documents
     * @return list<Document>
     */
    private function documents(iterable $documents): array
    {
        return array_values(is_array($documents) ? $documents : iterator_to_array($documents));
    }
}
