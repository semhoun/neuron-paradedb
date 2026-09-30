<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Tests\Integration;

use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
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
            PDO::ATTR_EMULATE_PREPARES => false,
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

    public function testPortableFiltersPreserveTypesAndScopeAcrossSearchAndDelete(): void
    {
        $schema = DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::float('weight')->filterable(),
            DocumentField::boolean('active')->filterable(),
            DocumentField::strings('tags')->filterable(),
        );
        $store = new ParadeDBVectorStore($this->pdo, 3, $this->table, 10, collectionId: 'A', schema: $schema);
        $payload = "O'Reilly'; DROP TABLE ignored; --";
        $documents = [
            $this->document('a', [1, 0, 0], 'web', $payload)->setMetadata(['tenant' => '01', 'year' => 2020, 'weight' => 1.5, 'active' => false, 'tags' => ['red', 'blue']]),
            $this->document('b', [0.8, 0.6, 0], 'file', 'two')->setMetadata(['tenant' => '1', 'year' => 2025, 'weight' => 2, 'active' => true, 'tags' => ['blue']]),
            $this->document('c', [0, 1, 0])->setMetadata(['tenant' => '01', 'year' => null, 'active' => null]),
        ];
        foreach ([$store, $store->forCollection('B'), $store->forCollection(null)] as $scoped) {
            $scoped->addDocuments($documents);
        }
        foreach ([
            [Filter::eq('tenant', '01'), ['a', 'c']],
            [Filter::neq('tenant', '01'), ['b']],
            [Filter::in('tenant', ['01', '1']), ['a', 'b', 'c']],
            [Filter::eq('active', false), ['a']],
            [Filter::gt('year', 2020), ['b']],
            [Filter::gte('year', 2020), ['a', 'b']],
            [Filter::lt('year', 2025), ['a']],
            [Filter::lte('year', 2025), ['a', 'b']],
            [Filter::eq('weight', 2.0), ['b']],
            [Filter::containsAny('tags', ['red', 'blue']), ['a', 'b']],
            [Filter::containsAll('tags', ['red', 'blue']), ['a']],
            [Filter::eq('sourceName', $payload), ['a']],
            [FilterGroup::allOf(Filter::eq('tenant', '01'), FilterGroup::anyOf(Filter::eq('sourceType', 'web'), Filter::eq('sourceName', 'two'))), ['a']],
        ] as [$filter, $expected]) {
            foreach ([$store->search(new SearchRequest([1, 0, 0], $filter)), $store->hybridSearch('nonexistentlexeme', [1, 0, 0], $filter)] as $results) {
                self::assertSame($expected, array_map(static fn (Document $document): string => (string) $document->getId(), $results));
            }
        }
        self::assertCount(1, $store->search(new SearchRequest([1, 0, 0], topK: 1)));
        self::assertCount(3, $store->search(new SearchRequest([1, 0, 0])));
        $store->delete(FilterGroup::anyOf(Filter::eq('sourceName', $payload), Filter::eq('tenant', '1')));
        self::assertSame(['c'], $this->storedIds('A'));
        self::assertSame(['a', 'b', 'c'], $this->storedIds('B'));
        self::assertSame(['a', 'b', 'c'], $this->storedIds());
    }

    public function testMetadataFiltersApplyBeforeCandidateRankingInBothHybridBranches(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required()->filterable());
        $store = new ParadeDBVectorStore($this->pdo, 3, $this->table, 2, collectionId: 'A', schema: $schema);
        for ($i = 0; $i < 8; ++$i) {
            $store->addDocument($this->withContent($this->document('outside-'.$i, [1, 0, 0]), 'needle')->setMetadata(['tenant' => 'other']));
        }
        $store->addDocuments([
            $this->document('local-vector', [0.8, 0.6, 0])->setMetadata(['tenant' => 'target']),
            $this->withContent($this->document('local-lexical', [0, 1, 0]), 'needle '.str_repeat('padding ', 100))->setMetadata(['tenant' => 'target']),
        ]);
        $filter = Filter::eq('tenant', 'target');
        $results = $store->hybridSearch('needle', [1, 0, 0], $filter);
        self::assertSame(['local-lexical', 'local-vector'], array_map(static fn (Document $document): string => (string) $document->getId(), $results));
        self::assertEqualsWithDelta(1 / 61 + 1 / 62, $results[0]->getScore(), 0.00000001);
        self::assertEqualsWithDelta(1 / 61, $results[1]->getScore(), 0.00000001);
        self::assertSame('local-vector', $store->search(new SearchRequest([1, 0, 0], $filter, 1))[0]->getId());
    }

    public function testSetupIsIdempotentAndPreservesDocuments(): void
    {
        $this->store->addDocument($this->document('retained', [1.0, 0.0, 0.0]));
        $collection = $this->store->forCollection('A');
        $collection->addDocument($this->document('retained', [1.0, 0.0, 0.0]));
        foreach ([$this->store, $collection, $this->store->forCollection('B')] as $store) {
            $store->setupDatabase();
        }
        self::assertSame(['retained'], $this->storedIds());
        self::assertSame(['retained'], $this->storedIds('A'));
    }

    public function testRejectsExistingTableWithDifferentDimensions(): void
    {
        $this->expectException(VectorStoreException::class);
        (new ParadeDBVectorStore($this->pdo, 4, $this->table))->setupDatabase();
    }

    public function testDropTableIsIdempotent(): void
    {
        $this->store->addDocument($this->document('global', [1.0, 0.0, 0.0]));
        $this->store->forCollection('A')->dropTable();
        $this->store->dropTable();
        $statement = $this->pdo->prepare('SELECT to_regclass(:table)');
        $statement->execute(['table' => $this->table]);
        self::assertNull($statement->fetchColumn());
    }

    public function testUpsertFullyHydratesBothSearchModes(): void
    {
        $document = $this->document("id'\\special", [0.0, 1.0, 0.0]);
        $this->store->addDocument($document);
        $document = $this->withContent($document, "uniquetoken O'Reilly \\ path \"quoted\"\nsecond line");
        $document->setSourceType("web' OR '1'='1");
        $document->setSourceName("name'; DROP TABLE example; --");
        $document->setEmbedding([1.0, 0.0, 0.0]);
        $document->setMetadata(['nested' => ['quote' => "O'Reilly", 'enabled' => true, 'nothing' => null], 'list' => [1, 2]]);
        self::assertSame($this->store, $this->store->addDocument($document));
        self::assertSame([(string) $document->getId()], $this->storedIds());

        foreach ([$this->store->similaritySearch([1.0, 0.0, 0.0]), $this->store->hybridSearch('uniquetoken', [1.0, 0.0, 0.0])] as $results) {
            $results = $this->documents($results);
            self::assertCount(1, $results);
            self::assertSame($document->getId(), $results[0]->getId());
            self::assertSame($document->getContent(), $results[0]->getContent());
            self::assertSame($document->getSourceType(), $results[0]->getSourceType());
            self::assertSame($document->getSourceName(), $results[0]->getSourceName());
            self::assertEquals($document->getMetadata(), $results[0]->getMetadata());
            self::assertEquals($document->getEmbedding(), $results[0]->getEmbedding());
        }
    }

    public function testSqlFailureRollsBackEntireBatchIncludingUpsert(): void
    {
        $existing = $this->document('existing', [1.0, 0.0, 0.0]);
        $this->store->addDocument($existing);
        $this->pdo->exec("ALTER TABLE {$this->table} ADD CONSTRAINT reject_bad CHECK (content <> 'rejectme')");
        $existing = $this->withContent($existing, 'changed');
        $bad = $this->document('bad', [0.0, 1.0, 0.0]);
        $bad = $this->withContent($bad, 'rejectme');
        try {
            $this->store->addDocuments([$existing, $this->document('new', [1.0, 0.0, 0.0]), $bad]);
            self::fail('The database constraint must reject the last document.');
        } catch (PDOException $exception) {
            self::assertSame('23514', (string) $exception->getCode());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(['existing'], $this->storedIds());
        self::assertSame('existing', $this->documents($this->store->similaritySearch([1.0, 0.0, 0.0]))[0]->getContent());
    }

    public function testJsonFailureRollsBackEntireBatch(): void
    {
        $bad = $this->document('bad', [0.0, 1.0, 0.0]);
        $bad->setMetadata(['invalid_utf8' => "\xB1\x31"]);
        try {
            $this->store->addDocuments([$this->document('good', [1.0, 0.0, 0.0]), $bad]);
            self::fail('Invalid UTF-8 metadata must fail JSON encoding.');
        } catch (DocumentSchemaException $exception) {
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
        $bad->setMetadata(['invalid_utf8' => "\xB1\x31"]);
        try {
            $this->store->addDocuments([$this->document('inner', [1.0, 0.0, 0.0]), $bad]);
            self::fail('Invalid JSON must reject the batch.');
        } catch (DocumentSchemaException $exception) {
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
        $bad = $this->withContent($bad, 'rejectme');
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
        self::assertSame('nearest', $results[0]->getId());
        self::assertSame('middle', $results[1]->getId());
        self::assertEqualsWithDelta(1.0, $results[0]->getScore(), 0.000001);
        self::assertEqualsWithDelta(0.8, $results[1]->getScore(), 0.000001);
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
            self::assertSame('uniquetoken', $results[0]->getId());
            self::assertSame('semantic', $results[1]->getId());
            self::assertEqualsWithDelta(1 / ($rrfK + 2) + 1 / ($rrfK + 1), $results[0]->getScore(), 0.00000001);
            self::assertEqualsWithDelta(1 / ($rrfK + 1), $results[1]->getScore(), 0.00000001);
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
        self::assertSame('nearest', $results[0]->getId());
        self::assertSame('middle', $results[1]->getId());
        self::assertEqualsWithDelta(1 / 61, $results[0]->getScore(), 0.00000001);
        self::assertEqualsWithDelta(1 / 62, $results[1]->getScore(), 0.00000001);
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

    public function testNumericallyUnsafeCosineVectorsAreRejected(): void
    {
        foreach ([[1e30, 0.0, 0.0], [1e-30, 0.0, 0.0]] as $embedding) {
            foreach (['add', 'similarity', 'hybrid'] as $operation) {
                try {
                    match ($operation) {
                        'add' => $this->store->addDocument($this->document('unsafe', $embedding)),
                        'similarity' => $this->store->similaritySearch($embedding),
                        'hybrid' => $this->store->hybridSearch('query', $embedding),
                    };
                    self::fail('Unsafe cosine norm must be rejected.');
                } catch (VectorStoreException $exception) {
                    self::assertStringContainsString('squared norm', $exception->getMessage());
                }
            }
        }
        self::assertSame([], $this->storedIds());
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
        self::assertSame('retained', $results[0]->getId());
        self::assertSame(['retained'], $this->storedIds());
    }

    public function testSamePublicIdIsIsolatedAcrossLiteralCollectionsAndUpserts(): void
    {
        $collections = [null, 'A', 'B', '0', '00', ' A ', ' ', "O'Reilly\\'; DROP TABLE ignored; --"];
        foreach ($collections as $offset => $collection) {
            $store = new ParadeDBVectorStore($this->pdo, 3, $this->table, 2, 60, $collection);
            $document = $this->document('same-public-id', [1.0, 0.0, 0.0]);
            $document = $this->withContent($document, 'sharedtoken content '.$offset);
            $document->setMetadata(['ragId' => 'not-the-scope', 'collection_id' => 'not-the-scope', 'offset' => $offset]);
            $store->addDocument($document);
        }
        $identities = $this->pdo->query("SELECT collection_id, search_id FROM {$this->table} ORDER BY collection_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertCount(count($collections), array_unique($identities));

        $changed = $this->document('same-public-id', [0.8, 0.6, 0.0]);
        $changed = $this->withContent($changed, 'sharedtoken updated');
        $changed->setMetadata(['updated' => true]);
        $this->store->forCollection('A')->addDocument($changed);
        self::assertSame($identities, $this->pdo->query("SELECT collection_id, search_id FROM {$this->table} ORDER BY collection_id")->fetchAll(PDO::FETCH_KEY_PAIR));

        foreach ($collections as $offset => $collection) {
            $store = $this->store->forCollection('unused')->forCollection($collection);
            foreach ([$store->similaritySearch([1.0, 0.0, 0.0]), $store->hybridSearch('sharedtoken', [1.0, 0.0, 0.0])] as $results) {
                self::assertCount(1, $results);
                self::assertSame('same-public-id', $results[0]->getId());
                self::assertSame($collection === 'A' ? $changed->getContent() : 'sharedtoken content '.$offset, $results[0]->getContent());
                self::assertEquals($collection === 'A' ? $changed->getMetadata() : ['ragId' => 'not-the-scope', 'collection_id' => 'not-the-scope', 'offset' => $offset], $results[0]->getMetadata());
            }
        }
        self::assertSame('sharedtoken content 0', $this->store->similaritySearch([1.0, 0.0, 0.0])[0]->getContent());
        $empty = $this->store->forCollection('empty');
        self::assertSame([], $empty->similaritySearch([1.0, 0.0, 0.0]));
        self::assertSame([], $empty->hybridSearch('sharedtoken', [1.0, 0.0, 0.0]));
        $empty->deleteBy('manual');
        self::assertCount(count($collections), $this->pdo->query("SELECT id FROM {$this->table}")->fetchAll());
    }

    public function testEveryDeletionFormIsScopedForNamedAndUnscopedStores(): void
    {
        foreach ([null, "O'Reilly\\' OR 1=1 --"] as $collection) {
            $target = $this->store->forCollection($collection);
            foreach (['native_pair', 'native_type', 'array_pair', 'array_type', 'array_name', 'deprecated'] as $form) {
                foreach ([$target, $this->store->forCollection('keep')] as $store) {
                    $store->addDocument($this->document('shared', [1.0, 0.0, 0.0], 'web', 'source'));
                    $store->addDocument($this->document('unrelated', [1.0, 0.0, 0.0], 'file', 'elsewhere'));
                }
                $result = match ($form) {
                    'native_pair' => $target->deleteBy('web', 'source'),
                    'native_type' => $target->deleteBy('web'),
                    'array_pair' => $target->deleteBy(['source_type' => 'web', 'source_name' => 'source']),
                    'array_type' => $target->deleteBy(['source_type' => 'web']),
                    'array_name' => $target->deleteBy(['source_name' => 'source']),
                    'deprecated' => $target->deleteBySource('web', 'source'),
                };
                self::assertSame($target, $result);
                self::assertSame(['unrelated'], $this->storedIds($collection));
                self::assertSame(['shared', 'unrelated'], $this->storedIds('keep'));
            }
        }
    }

    public function testVectorCandidatesAreFilteredBeforeBothSearchLimits(): void
    {
        $target = $this->store->forCollection('target');
        $target->addDocuments([
            $this->document('local-first', [0.8, 0.6, 0.0]),
            $this->document('local-second', [0.6, 0.8, 0.0]),
        ]);
        foreach ([null, 'other'] as $collection) {
            for ($i = 0; $i < 6; ++$i) {
                $this->store->forCollection($collection)->addDocument($this->document('outside-'.$i, [1.0, 0.0, 0.0]));
            }
        }
        foreach ([$target->similaritySearch([1.0, 0.0, 0.0]), $target->hybridSearch('nonexistentlexeme', [1.0, 0.0, 0.0])] as $results) {
            self::assertSame(['local-first', 'local-second'], array_map(static fn (Document $document): string => (string) $document->getId(), $results));
        }
        $results = $target->hybridSearch('nonexistentlexeme', [1.0, 0.0, 0.0]);
        self::assertEqualsWithDelta(1 / 61, $results[0]->getScore(), 0.00000001);
        self::assertEqualsWithDelta(1 / 62, $results[1]->getScore(), 0.00000001);
    }

    public function testLexicalCandidatesAreFilteredBeforeLimitAndRanking(): void
    {
        $target = $this->store->forCollection('target');
        for ($i = 0; $i < 5; ++$i) {
            $target->addDocument($this->document('semantic-'.$i, [1.0, $i / 10, 0.0]));
        }
        $lexical = $this->document('lexical-only', [0.0, 1.0, 0.0]);
        $lexical = $this->withContent($lexical, 'needle '.str_repeat('padding ', 100));
        $target->addDocument($lexical);
        for ($i = 0; $i < 6; ++$i) {
            $outside = $this->document('outside-'.$i, [1.0, 0.0, 0.0]);
            $outside = $this->withContent($outside, 'needle');
            $this->store->forCollection('other')->addDocument($outside);
        }
        // Prove the fixture would starve the local lexical candidate without a pre-limit filter.
        $global = $this->pdo->query("SELECT collection_id FROM {$this->table} WHERE content ||| 'needle' ORDER BY pdb.score(search_id) DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['other', 'other', 'other', 'other'], $global);
        $results = $target->hybridSearch('needle', [1.0, 0.0, 0.0]);
        self::assertSame(['lexical-only', 'semantic-0'], array_map(static fn (Document $document): string => (string) $document->getId(), $results));
        foreach ($results as $result) {
            self::assertEqualsWithDelta(1 / 61, $result->getScore(), 0.00000001);
        }
    }

    public function testProvisionedSchemaHasCompositePrimaryKeyAndAlwaysIdentity(): void
    {
        $primary = $this->pdo->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = '{$this->table}'::regclass AND contype = 'p'")->fetchColumn();
        self::assertSame('PRIMARY KEY (collection_id, id)', $primary);
        $columns = $this->pdo->query("SELECT column_name, data_type, is_nullable, column_default, identity_generation FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$this->table}'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        self::assertSame('text', $columns['collection_id']['data_type']);
        self::assertSame('NO', $columns['collection_id']['is_nullable']);
        self::assertSame("''::text", $columns['collection_id']['column_default']);
        self::assertSame('bigint', $columns['search_id']['data_type']);
        self::assertSame('NO', $columns['search_id']['is_nullable']);
        self::assertSame('ALWAYS', $columns['search_id']['identity_generation']);
        $this->store->addDocument($this->document('first', [1.0, 0.0, 0.0]));
        try {
            $this->pdo->exec("INSERT INTO {$this->table} (collection_id, id, search_id, content, source_type, source_name, embedding) OVERRIDING SYSTEM VALUE SELECT 'other', id, search_id, content, source_type, source_name, embedding FROM {$this->table}");
            self::fail('search_id must be globally unique, not merely unique within a collection.');
        } catch (PDOException $exception) {
            self::assertSame('23505', (string) $exception->getCode());
        }
    }

    public function testRejectsMalformedCollectionAndIdentitySchemas(): void
    {
        foreach ([
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE', 'PRIMARY KEY (id, collection_id)'],
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE', 'PRIMARY KEY (collection_id, id) DEFERRABLE'],
            ['collection_id VARCHAR(255) NOT NULL DEFAULT \'\'', 'search_id BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE', 'PRIMARY KEY (collection_id, id)'],
            ['collection_id TEXT DEFAULT \'\'', 'search_id BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE', 'PRIMARY KEY (id)'],
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id BIGINT NOT NULL UNIQUE', 'PRIMARY KEY (collection_id, id)'],
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id BIGINT GENERATED BY DEFAULT AS IDENTITY UNIQUE', 'PRIMARY KEY (collection_id, id)'],
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id INTEGER GENERATED ALWAYS AS IDENTITY UNIQUE', 'PRIMARY KEY (collection_id, id)'],
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id BIGINT GENERATED ALWAYS AS IDENTITY', 'PRIMARY KEY (collection_id, id)'],
            ['collection_id TEXT NOT NULL DEFAULT \'\'', 'search_id BIGINT GENERATED ALWAYS AS IDENTITY', 'PRIMARY KEY (collection_id, id), UNIQUE (collection_id, search_id)'],
        ] as [$collection, $identity, $primary]) {
            $this->store->dropTable();
            $this->pdo->exec("CREATE TABLE {$this->table} (id TEXT NOT NULL, {$collection}, {$identity}, content TEXT NOT NULL, source_type TEXT NOT NULL, source_name TEXT NOT NULL, metadata JSONB NOT NULL DEFAULT '{}'::jsonb, embedding VECTOR(3) NOT NULL, {$primary})");
            try {
                new ParadeDBVectorStore($this->pdo, 3, $this->table);
                self::fail('An incompatible schema was accepted: '.$collection.', '.$identity.', '.$primary);
            } catch (VectorStoreException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testRejectsWrongIndexDefinitionButAllowsMissingIndexes(): void
    {
        $this->pdo->exec("DROP INDEX {$this->table}_bm25_idx");
        $this->pdo->exec("DROP INDEX {$this->table}_embedding_idx");
        $store = new ParadeDBVectorStore($this->pdo, 3, $this->table);
        foreach ([
            "CREATE INDEX {$this->table}_bm25_idx ON {$this->table} (search_id, content)",
            "CREATE INDEX {$this->table}_bm25_idx ON {$this->table} USING bm25 (id, content) WITH (key_field = 'id')",
        ] as $sql) {
            $this->pdo->exec("CREATE UNIQUE INDEX {$this->table}_legacy_unique ON {$this->table} (id)");
            $this->pdo->exec($sql);
            foreach (['constructor', 'setup'] as $operation) {
                try {
                    if ($operation === 'constructor') {
                        new ParadeDBVectorStore($this->pdo, 3, $this->table);
                    } else {
                        $store->setupDatabase();
                    }
                    self::fail('An incompatible index with the expected name was accepted.');
                } catch (VectorStoreException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
            $this->pdo->exec("DROP INDEX {$this->table}_bm25_idx");
            $this->pdo->exec("DROP INDEX {$this->table}_legacy_unique");
        }
        $store->setupDatabase();
        $store->forCollection('A')->addDocument($this->document('needle', [1.0, 0.0, 0.0]));
        self::assertSame('needle', $store->forCollection('A')->hybridSearch('needle', [1.0, 0.0, 0.0])[0]->getId());
    }

    public function testLegacyMigrationPreservesDataAndSupportsScopedSearchAndUpsert(): void
    {
        $this->createLegacyTable();
        $before = $this->legacyRows();
        $hnsw = $this->pdo->query("SELECT oid FROM pg_class WHERE relname = '{$this->table}_embedding_idx'")->fetchColumn();
        foreach (['constructor', 'setup'] as $operation) {
            try {
                if ($operation === 'constructor') {
                    new ParadeDBVectorStore($this->pdo, 3, $this->table);
                } else {
                    $this->store->setupDatabase();
                }
                self::fail('Legacy tables must require explicit migration.');
            } catch (VectorStoreException $exception) {
                self::assertStringContainsString('migrat', strtolower($exception->getMessage()));
            }
        }
        self::assertSame($before, $this->legacyRows());
        $this->pdo->exec("ALTER TABLE {$this->table} RENAME CONSTRAINT {$this->table}_legacy_pk TO \"Legacy primary \"\"key\"");
        $this->migrateLegacyTable();
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($before, $this->legacyRows());
        self::assertSame($hnsw, $this->pdo->query("SELECT oid FROM pg_class WHERE relname = '{$this->table}_embedding_idx'")->fetchColumn());
        self::assertSame([''], $this->pdo->query("SELECT DISTINCT collection_id FROM {$this->table}")->fetchAll(PDO::FETCH_COLUMN));
        $identities = $this->pdo->query("SELECT search_id FROM {$this->table} ORDER BY search_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->migrateLegacyTable();
        self::assertSame($identities, $this->pdo->query("SELECT search_id FROM {$this->table} ORDER BY search_id")->fetchAll(PDO::FETCH_COLUMN));

        $store = new ParadeDBVectorStore($this->pdo, 3, $this->table);
        $store->setupDatabase();
        foreach ([$store->similaritySearch([1.0, 0.0, 0.0]), $store->hybridSearch('legacytoken', [1.0, 0.0, 0.0])] as $results) {
            self::assertCount(1, $results);
            self::assertSame("legacy'\\id", $results[0]->getId());
            self::assertEquals(['nested' => ['value' => "O'Reilly"], 'collection_id' => 'metadata-only'], $results[0]->getMetadata());
        }
        $identity = $this->pdo->query("SELECT search_id FROM {$this->table}")->fetchColumn();
        $updated = $this->document("legacy'\\id", [0.8, 0.6, 0.0]);
        $updated = $this->withContent($updated, 'legacytoken updated');
        $store->addDocument($updated);
        self::assertSame($identity, $this->pdo->query("SELECT search_id FROM {$this->table}")->fetchColumn());
        $updated = $this->withContent($updated, 'legacytoken named');
        $store->forCollection('named')->addDocument($updated);
        self::assertSame('legacytoken updated', $store->hybridSearch('legacytoken', [1.0, 0.0, 0.0])[0]->getContent());
        self::assertSame('legacytoken named', $store->forCollection('named')->hybridSearch('legacytoken', [1.0, 0.0, 0.0])[0]->getContent());
    }

    public function testInterruptedLegacyMigrationRollsBackSchemaIndexesAndData(): void
    {
        $this->createLegacyTable();
        $before = $this->legacyRows();
        $indexes = $this->pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = '{$this->table}' ORDER BY indexname")->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->beginTransaction();
        try {
            $this->migrateLegacyTable();
            self::assertTrue($this->pdo->inTransaction());
            $this->pdo->exec('SELECT 1 / 0');
            self::fail('The interrupted migration must fail before commit.');
        } catch (PDOException $exception) {
            self::assertSame('22012', (string) $exception->getCode());
            $this->pdo->rollBack();
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame($before, $this->legacyRows());
        self::assertSame($indexes, $this->pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = '{$this->table}' ORDER BY indexname")->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(['id', 'content', 'source_type', 'source_name', 'metadata', 'embedding'], $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$this->table}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame("legacy'\\id", $this->pdo->query("SELECT id FROM {$this->table} WHERE content ||| 'legacytoken' ORDER BY pdb.score(id) DESC")->fetchColumn());
    }

    public function testRuntimeRoleCanUseIdentityAndSearchWithoutSequenceOrDdlPrivileges(): void
    {
        $role = 'test_runtime_'.bin2hex(random_bytes(8));
        $this->pdo->exec("CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT");
        try {
            $this->pdo->exec("GRANT USAGE ON SCHEMA public TO {$role}");
            $this->pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON {$this->table} TO {$role}");
            $this->pdo->exec("SET ROLE {$role}");
            self::assertSame($role, $this->pdo->query('SELECT current_user')->fetchColumn());
            self::assertFalse($this->pdo->query("SELECT has_sequence_privilege(current_user, pg_get_serial_sequence('{$this->table}', 'search_id'), 'USAGE, SELECT, UPDATE')")->fetchColumn());
            self::assertFalse($this->pdo->query("SELECT has_table_privilege(current_user, '{$this->table}', 'TRUNCATE')")->fetchColumn());
            $store = new ParadeDBVectorStore($this->pdo, 3, $this->table, 2, 60, 'runtime');
            $document = $this->document('runtime-id', [1.0, 0.0, 0.0]);
            $document = $this->withContent($document, 'runtimetoken initial');
            $store->addDocument($document);
            $identity = $this->pdo->query("SELECT search_id FROM {$this->table}")->fetchColumn();
            $document = $this->withContent($document, 'runtimetoken updated');
            $store->addDocument($document);
            self::assertSame($identity, $this->pdo->query("SELECT search_id FROM {$this->table}")->fetchColumn());
            foreach ([$store->similaritySearch([1.0, 0.0, 0.0]), $store->hybridSearch('runtimetoken', [1.0, 0.0, 0.0])] as $results) {
                self::assertCount(1, $results);
                self::assertSame('runtime-id', $results[0]->getId());
                self::assertSame('runtimetoken updated', $results[0]->getContent());
            }
            $store->deleteBy('manual');
            self::assertSame([], $this->storedIds('runtime'));
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('RESET ROLE');
            $this->pdo->exec("DROP OWNED BY {$role}");
            $this->pdo->exec("DROP ROLE {$role}");
        }
    }

    public function testScopedBatchFailurePreservesIdentitiesAndOuterTransaction(): void
    {
        $this->store->addDocument($this->document('shared', [1.0, 0.0, 0.0]));
        $target = $this->store->forCollection('target');
        $target->addDocument($this->document('shared', [1.0, 0.0, 0.0]));
        $before = $this->pdo->query("SELECT collection_id, search_id, content FROM {$this->table} ORDER BY collection_id")->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->exec("ALTER TABLE {$this->table} ADD CHECK (content <> 'rejectme')");
        $this->pdo->beginTransaction();
        $this->store->addDocument($this->document('outer', [1.0, 0.0, 0.0]));
        $changed = $this->document('shared', [0.0, 1.0, 0.0]);
        $changed = $this->withContent($changed, 'changed');
        $bad = $this->document('bad', [1.0, 0.0, 0.0]);
        $bad = $this->withContent($bad, 'rejectme');
        try {
            $target->addDocuments([$changed, $this->document('new', [1.0, 0.0, 0.0]), $bad]);
            self::fail('The scoped batch must roll back to its savepoint.');
        } catch (PDOException $exception) {
            self::assertSame('23514', (string) $exception->getCode());
        }
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame($before, $this->pdo->query("SELECT collection_id, search_id, content FROM {$this->table} WHERE id = 'shared' ORDER BY collection_id")->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(['shared'], $this->storedIds('target'));
        self::assertSame(['outer', 'shared'], $this->storedIds());
        $target->addDocument($this->document('after', [1.0, 0.0, 0.0]));
        $this->pdo->commit();
        self::assertSame(['after', 'shared'], $this->storedIds('target'));
    }

    public function testUpgradeFailureRestoresLegacySchemaAndCallerTransaction(): void
    {
        foreach ([false, true] as $outerTransaction) {
            $this->createLegacyTable();
            $before = $this->legacyRows();
            $indexes = $this->pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = '{$this->table}' ORDER BY indexname")->fetchAll(PDO::FETCH_ASSOC);
            $dependent = $this->table.'_refs';
            $this->pdo->exec("CREATE TABLE {$dependent} (id TEXT REFERENCES {$this->table}(id))");
            try {
                if ($outerTransaction) {
                    $this->pdo->beginTransaction();
                    $this->pdo->exec("INSERT INTO {$dependent} VALUES (NULL)");
                }
                try {
                    $this->migrateLegacyTable();
                    self::fail('An external foreign key must prevent replacing the primary key.');
                } catch (PDOException $exception) {
                    self::assertSame('2BP01', (string) $exception->getCode());
                }
                self::assertSame($outerTransaction, $this->pdo->inTransaction());
                self::assertSame($before, $this->legacyRows());
                self::assertSame($indexes, $this->pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = '{$this->table}' ORDER BY indexname")->fetchAll(PDO::FETCH_ASSOC));
                self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_name = '{$this->table}' AND column_name IN ('search_id', 'collection_id')")->fetchColumn());
                self::assertSame("legacy'\\id", $this->pdo->query("SELECT id FROM {$this->table} WHERE content ||| 'legacytoken'")->fetchColumn());
                if ($outerTransaction) {
                    self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM {$dependent}")->fetchColumn());
                    $this->pdo->commit();
                }
            } finally {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->pdo->exec("DROP TABLE {$dependent}");
            }
        }
    }

    public function testUpgradeRejectsWrongDimensionsAndPartialSchemasWithoutChangingData(): void
    {
        foreach (['dimensions', 'partial', 'primary_key', 'index'] as $invalid) {
            $this->createLegacyTable();
            if ($invalid === 'partial') {
                $this->pdo->exec("ALTER TABLE {$this->table} ADD collection_id TEXT NOT NULL DEFAULT ''");
            } elseif ($invalid === 'primary_key') {
                $this->pdo->exec("ALTER TABLE {$this->table} DROP CONSTRAINT {$this->table}_legacy_pk");
            } elseif ($invalid === 'index') {
                $this->pdo->exec("DROP INDEX {$this->table}_bm25_idx");
                $this->pdo->exec("CREATE INDEX {$this->table}_bm25_idx ON {$this->table} (id)");
            }
            $before = $this->legacyRows();
            $columns = $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$this->table}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
            try {
                ParadeDBVectorStore::upgradeToV11($this->pdo, $invalid === 'dimensions' ? 4 : 3, $this->table);
                self::fail('An incompatible schema must not be upgraded: '.$invalid);
            } catch (VectorStoreException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame($before, $this->legacyRows());
            self::assertSame($columns, $this->pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '{$this->table}' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    public function testUpgradeRejectsMissingTable(): void
    {
        $this->store->dropTable();
        try {
            $this->migrateLegacyTable();
            self::fail('Upgrading must not silently provision a missing table.');
        } catch (VectorStoreException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertNull($this->pdo->query("SELECT to_regclass('{$this->table}')")->fetchColumn());
    }

    public function testUpgradeCreatesMissingBm25Index(): void
    {
        $this->createLegacyTable();
        $this->pdo->exec("DROP INDEX {$this->table}_bm25_idx");
        $this->migrateLegacyTable();
        $store = new ParadeDBVectorStore($this->pdo, 3, $this->table);
        self::assertSame("legacy'\\id", $store->hybridSearch('legacytoken', [1.0, 0.0, 0.0])[0]->getId());
    }

    public function testUpgradeDoesNotDropIndexBelongingToAnotherTable(): void
    {
        $this->createLegacyTable();
        $this->pdo->exec("DROP INDEX {$this->table}_bm25_idx");
        $other = $this->table.'_other';
        $this->pdo->exec("CREATE TABLE {$other} (id TEXT)");
        $this->pdo->exec("CREATE INDEX {$this->table}_bm25_idx ON {$other} (id)");
        try {
            try {
                $this->migrateLegacyTable();
                self::fail('A same-name index on another table must not be dropped.');
            } catch (VectorStoreException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertSame($other, $this->pdo->query("SELECT tablename FROM pg_indexes WHERE indexname = '{$this->table}_bm25_idx'")->fetchColumn());
        } finally {
            $this->pdo->exec("DROP TABLE {$other}");
        }
    }

    public function testUpgradeResolvesTableAndIndexInTheirActualSchema(): void
    {
        $this->store->dropTable();
        $schema = 'upgrade_'.bin2hex(random_bytes(6));
        $this->pdo->exec("CREATE SCHEMA {$schema}");
        try {
            $this->pdo->exec("SET search_path TO {$schema}, public");
            $this->createLegacyTable();
            $this->pdo->exec("CREATE TABLE public.{$this->table} (id TEXT)");
            $this->pdo->exec("CREATE INDEX {$this->table}_bm25_idx ON public.{$this->table} (id)");
            $this->migrateLegacyTable();
            $store = new ParadeDBVectorStore($this->pdo, 3, $this->table);
            self::assertSame("legacy'\\id", $store->hybridSearch('legacytoken', [1.0, 0.0, 0.0])[0]->getId());
            self::assertSame('btree', $this->pdo->query("SELECT am.amname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_am am ON am.oid = c.relam WHERE c.relname = '{$this->table}_bm25_idx' AND n.nspname = 'public'")->fetchColumn());
        } finally {
            $this->pdo->exec("DROP TABLE IF EXISTS {$schema}.{$this->table}");
            $this->pdo->exec('SET search_path TO public');
            $this->pdo->exec("DROP SCHEMA {$schema}");
        }
    }

    private function createLegacyTable(): void
    {
        $this->store->dropTable();
        $this->pdo->exec("CREATE TABLE {$this->table} (id TEXT CONSTRAINT {$this->table}_legacy_pk PRIMARY KEY, content TEXT NOT NULL, source_type VARCHAR(255) NOT NULL, source_name VARCHAR(255) NOT NULL, metadata JSONB NOT NULL DEFAULT '{}'::jsonb, embedding VECTOR(3) NOT NULL)");
        $this->pdo->exec("CREATE INDEX {$this->table}_bm25_idx ON {$this->table} USING bm25 (id, content) WITH (key_field = 'id')");
        $this->pdo->exec("CREATE INDEX {$this->table}_embedding_idx ON {$this->table} USING hnsw (embedding vector_cosine_ops)");
        $statement = $this->pdo->prepare("INSERT INTO {$this->table} VALUES (:id, :content, :type, :name, CAST(:metadata AS jsonb), '[1,0,0]')");
        $statement->execute([
            'id' => "legacy'\\id", 'content' => "legacytoken O'Reilly\\path", 'type' => 'legacy', 'name' => 'legacy/source',
            'metadata' => json_encode(['nested' => ['value' => "O'Reilly"], 'collection_id' => 'metadata-only'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function migrateLegacyTable(): void
    {
        ParadeDBVectorStore::upgradeToV11($this->pdo, 3, $this->table);
    }

    /** @return list<array<string, mixed>> */
    private function legacyRows(): array
    {
        return $this->pdo->query("SELECT id, content, source_type, source_name, metadata::text, embedding::text FROM {$this->table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param float[] $embedding */
    private function document(string $id, array $embedding, string $sourceType = 'manual', string $sourceName = 'manual'): Document
    {
        $document = new Document($id);
        $document->setId($id);
        $document->setEmbedding($embedding);
        $document->setSourceType($sourceType);
        $document->setSourceName($sourceName);
        return $document;
    }

    private function withContent(Document $document, string $content): Document
    {
        return (new Document($content))->setId($document->getId())
            ->setEmbedding($document->getEmbedding())->setMetadata($document->getMetadata())
            ->setSourceType($document->getSourceType())->setSourceName($document->getSourceName());
    }

    /** @return list<string> */
    private function storedIds(?string $collectionId = null): array
    {
        $statement = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE collection_id = :collection_id ORDER BY id");
        $statement->execute(['collection_id' => $collectionId ?? '']);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
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
