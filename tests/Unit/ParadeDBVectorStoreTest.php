<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Tests\Unit;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Semhoun\NeuronParadeDB\VectorStore\HybridVectorStoreInterface;
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBVectorStore;

final class ParadeDBVectorStoreTest extends TestCase
{
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
        foreach ([[], ['unknown' => 'value'], ['source_type' => 'web', 'unknown' => 'value']] as $filter) {
            $pdo = $this->pdoWithMissingTable();
            $store = new ParadeDBVectorStore($pdo, 3);
            try {
                $store->deleteBy($filter);
                self::fail('Empty or unknown filters must not execute a deletion.');
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
