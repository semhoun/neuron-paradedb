<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\VectorStore;

use InvalidArgumentException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\HasDocumentSchema;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PDO;
use PDOStatement;
use Throwable;

class ParadeDBVectorStore implements HybridVectorStoreInterface
{
    use HasDocumentSchema;

    protected readonly string $tableName;
    protected readonly string $bm25IndexName;
    protected readonly string $vectorIndexName;

    public function __construct(
        protected PDO $pdo,
        protected readonly int $dimensions = 1536,
        string $tableName = 'rag_documents',
        protected readonly int $topK = 4,
        protected readonly int $rrfK = 60,
        protected ?string $collectionId = null,
        ?DocumentSchema $schema = null,
    ) {
        if ($collectionId === '') {
            throw new InvalidArgumentException('Collection IDs must be nonempty; use null for no collection.');
        }
        if ($dimensions < 1 || $topK < 1 || $rrfK < 1) {
            throw new InvalidArgumentException('Dimensions, topK and rrfK must be positive integers.');
        }
        if (strlen($tableName) > 48 || preg_match('/^[a-z_][a-z0-9_]*$/iD', $tableName) !== 1) {
            throw new InvalidArgumentException('Table names must be SQL identifiers of at most 48 ASCII characters.');
        }

        $this->initializeSchema($schema);
        $this->tableName = '"'.$tableName.'"';
        $this->bm25IndexName = '"'.$tableName.'_bm25_idx"';
        $this->vectorIndexName = '"'.$tableName.'_embedding_idx"';
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->validateSchema();
    }

    /** Explicit administrative migration; requires exclusive access and permission to alter the table and indexes. */
    public static function upgradeToV11(PDO $pdo, int $dimensions = 1536, string $tableName = 'rag_documents'): void
    {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Dimensions must be a positive integer.');
        }
        if (strlen($tableName) > 48 || preg_match('/^[a-z_][a-z0-9_]*$/iD', $tableName) !== 1) {
            throw new InvalidArgumentException('Table names must be SQL identifiers of at most 48 ASCII characters.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
        $execute = static function (string $sql) use ($pdo): void {
            if ($pdo->exec($sql) === false) {
                throw new VectorStoreException('Database operation failed: '.($pdo->errorInfo()[2] ?? 'unknown error'));
            }
        };
        /** @param array<string, mixed> $parameters */
        $query = static function (string $sql, array $parameters) use ($pdo): PDOStatement {
            $statement = $pdo->prepare($sql);
            if ($statement === false) {
                throw new VectorStoreException('Database preparation failed: '.($pdo->errorInfo()[2] ?? 'unknown error'));
            }
            if (!$statement->execute($parameters)) {
                throw new VectorStoreException('Database execution failed: '.($statement->errorInfo()[2] ?? 'unknown error'));
            }

            return $statement;
        };
        $savepoint = $pdo->inTransaction() ? $quote('neuron_paradedb_'.bin2hex(random_bytes(12))) : null;
        if ($savepoint !== null) {
            $execute('SAVEPOINT '.$savepoint);
        } elseif (!$pdo->beginTransaction()) {
            throw new VectorStoreException('Unable to begin the upgrade transaction.');
        }

        try {
            $table = $query(<<<'SQL'
                SELECT c.oid, c.relkind, c.relname, n.nspname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.oid = pg_catalog.to_regclass(:table_name)
                SQL, [':table_name' => $quote($tableName)])->fetch(PDO::FETCH_ASSOC);
            if ($table === false) {
                throw new VectorStoreException('Cannot upgrade a missing table: '.$tableName);
            }
            if (!in_array($table['relkind'], ['r', 'p'], true)) {
                throw new VectorStoreException('The configured relation must be a table.');
            }
            $qualifiedTable = $quote($table['nspname']).'.'.$quote($table['relname']);
            $execute('LOCK TABLE '.$qualifiedTable.' IN ACCESS EXCLUSIVE MODE');
            // Detect replacement between catalog resolution and acquiring the lock.
            $oid = $query('SELECT pg_catalog.to_regclass(:table_name)::oid', [':table_name' => $qualifiedTable])->fetchColumn();
            if ((string) $oid !== (string) $table['oid']) {
                throw new VectorStoreException('The table changed while acquiring the upgrade lock.');
            }
            $newColumns = (int) $query(<<<'SQL'
                SELECT count(*) FROM pg_catalog.pg_attribute
                WHERE attrelid = :oid AND attnum > 0 AND NOT attisdropped
                  AND attname IN ('collection_id', 'search_id')
                SQL, [':oid' => $table['oid']])->fetchColumn();
            if ($newColumns === 1) {
                throw new VectorStoreException('Cannot upgrade a partially migrated table.');
            }
            if ($newColumns === 0) {
                $primaryKey = $query(<<<'SQL'
                    SELECT c.conname FROM pg_catalog.pg_constraint c
                    JOIN pg_catalog.pg_attribute a ON a.attrelid = c.conrelid
                      AND a.attname = 'id' AND NOT a.attisdropped
                    WHERE c.conrelid = :oid AND c.contype = 'p' AND NOT c.condeferrable
                      AND c.conkey = ARRAY[a.attnum]
                    SQL, [':oid' => $table['oid']])->fetchColumn();
                if (!is_string($primaryKey)) {
                    throw new VectorStoreException('Legacy schema must have a non-deferrable primary key on id alone.');
                }
                $indexName = $tableName.'_bm25_idx';
                $index = $query(<<<'SQL'
                    SELECT c.relname,
                           am.amname = 'bm25' AND i.indrelid = t.oid AND i.indisvalid
                           AND i.indpred IS NULL AND i.indexprs IS NULL AND i.indnkeyatts = 2
                           AND pg_catalog.pg_get_indexdef(c.oid, 1, true) = 'id'
                           AND pg_catalog.pg_get_indexdef(c.oid, 2, true) = 'content'
                           AND EXISTS (SELECT 1 FROM pg_catalog.pg_options_to_table(c.reloptions)
                               WHERE option_name = 'key_field' AND option_value = 'id') AS compatible
                    FROM pg_catalog.pg_class t
                    JOIN pg_catalog.pg_class c ON c.relnamespace = t.relnamespace AND c.relname = :index_name
                    LEFT JOIN pg_catalog.pg_index i ON i.indexrelid = c.oid
                    LEFT JOIN pg_catalog.pg_am am ON am.oid = c.relam
                    WHERE t.oid = :oid
                    SQL, [':oid' => $table['oid'], ':index_name' => $indexName])->fetch(PDO::FETCH_ASSOC);
                if ($index !== false) {
                    if (!in_array($index['compatible'], [true, 1, '1', 't'], true)) {
                        throw new VectorStoreException('Cannot replace incompatible object named '.$indexName.'.');
                    }
                    $execute('DROP INDEX '.$quote($table['nspname']).'.'.$quote($index['relname']));
                }
                $execute('ALTER TABLE '.$qualifiedTable." ADD COLUMN collection_id TEXT NOT NULL DEFAULT '',"
                    .' ADD COLUMN search_id BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE');
                $execute('ALTER TABLE '.$qualifiedTable.' DROP CONSTRAINT '.$quote($primaryKey)
                    .', ADD PRIMARY KEY (collection_id, id)');
                $execute('CREATE INDEX '.$quote($indexName).' ON '.$qualifiedTable
                    ." USING bm25 (search_id, content) WITH (key_field = 'search_id')");
            } else {
                // Validate named objects before allowing IF NOT EXISTS to keep an existing index.
                new self($pdo, $dimensions, $tableName);
                $execute('CREATE INDEX IF NOT EXISTS '.$quote($tableName.'_bm25_idx').' ON '.$qualifiedTable
                    ." USING bm25 (search_id, content) WITH (key_field = 'search_id')");
            }
            // The regular constructor remains the authority for the resulting 1.1 schema.
            new self($pdo, $dimensions, $tableName);
            if ($savepoint !== null) {
                $execute('RELEASE SAVEPOINT '.$savepoint);
            } elseif (!$pdo->commit()) {
                throw new VectorStoreException('Unable to commit the upgrade transaction.');
            }
        } catch (Throwable $error) {
            try {
                if ($savepoint !== null) {
                    $execute('ROLLBACK TO SAVEPOINT '.$savepoint);
                    $execute('RELEASE SAVEPOINT '.$savepoint);
                } elseif ($pdo->inTransaction() && !$pdo->rollBack()) {
                    throw new VectorStoreException('Unable to roll back the upgrade transaction.');
                }
            } catch (Throwable $rollbackError) {
                throw new VectorStoreException('Schema upgrade failed; rollback also failed: '.$rollbackError->getMessage(), 0, $error);
            }
            throw $error;
        }
    }

    public function forCollection(?string $collectionId): static
    {
        if ($collectionId === '') {
            throw new InvalidArgumentException('Collection IDs must be nonempty; use null for no collection.');
        }
        $store = clone $this;
        $store->collectionId = $collectionId;

        return $store;
    }

    /** Requires permission to create the vector and pg_search extensions, table and indexes. */
    public function setupDatabase(): void
    {
        $this->validateSchema();
        $this->executeSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->executeSql('CREATE EXTENSION IF NOT EXISTS pg_search');
        $this->executeSql(sprintf(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS %s (
                    collection_id TEXT NOT NULL DEFAULT '',
                    id TEXT NOT NULL,
                    search_id BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE,
                    content TEXT NOT NULL,
                    source_type VARCHAR(255) NOT NULL,
                    source_name VARCHAR(255) NOT NULL,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding VECTOR(%d) NOT NULL,
                    PRIMARY KEY (collection_id, id)
                )
                SQL,
            $this->tableName,
            $this->dimensions,
        ));
        $this->validateSchema();
        $this->executeSql(sprintf(
            "CREATE INDEX IF NOT EXISTS %s ON %s USING bm25 (search_id, content) WITH (key_field = 'search_id')",
            $this->bm25IndexName,
            $this->tableName,
        ));
        $this->executeSql(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING hnsw (embedding vector_cosine_ops)',
            $this->vectorIndexName,
            $this->tableName,
        ));
    }

    public function dropTable(): void
    {
        $this->executeSql('DROP TABLE IF EXISTS '.$this->tableName);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /** @param Document[] $documents */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                throw new InvalidArgumentException('Every batch entry must be a Document.');
            }
            $this->validateDocument($document);
            $this->encodeEmbedding($document->getEmbedding() ?? []);
        }

        $savepoint = $this->pdo->inTransaction() ? '"neuron_paradedb_'.bin2hex(random_bytes(12)).'"' : null;
        if ($savepoint !== null) {
            $this->executeSql('SAVEPOINT '.$savepoint);
        } elseif (!$this->pdo->beginTransaction()) {
            throw new VectorStoreException('Unable to begin the document transaction.');
        }

        try {
            $statement = $this->prepare(sprintf(
                <<<'SQL'
                    INSERT INTO %s (collection_id, id, content, source_type, source_name, metadata, embedding)
                    VALUES (:collection_id, :id, :content, :source_type, :source_name, CAST(:metadata AS jsonb), CAST(:embedding AS vector))
                    ON CONFLICT (collection_id, id) DO UPDATE SET
                        content = EXCLUDED.content,
                        source_type = EXCLUDED.source_type,
                        source_name = EXCLUDED.source_name,
                        metadata = EXCLUDED.metadata,
                        embedding = EXCLUDED.embedding
                    SQL,
                $this->tableName,
            ));
            foreach ($documents as $document) {
                $this->executeStatement($statement, [
                    ':collection_id' => $this->collectionId ?? '',
                    ':id' => (string) $document->getId(),
                    ':content' => $document->getContent(),
                    ':source_type' => $document->getSourceType(),
                    ':source_name' => $document->getSourceName(),
                    ':metadata' => json_encode((object) $document->getMetadata(), JSON_THROW_ON_ERROR),
                    ':embedding' => $this->encodeEmbedding($document->getEmbedding() ?? []),
                ]);
            }
            if ($savepoint !== null) {
                $this->executeSql('RELEASE SAVEPOINT '.$savepoint);
            } elseif (!$this->pdo->commit()) {
                throw new VectorStoreException('Unable to commit the document transaction.');
            }
        } catch (Throwable $error) {
            try {
                if ($savepoint !== null) {
                    $this->executeSql('ROLLBACK TO SAVEPOINT '.$savepoint);
                    $this->executeSql('RELEASE SAVEPOINT '.$savepoint);
                } elseif ($this->pdo->inTransaction() && !$this->pdo->rollBack()) {
                    throw new VectorStoreException('Unable to roll back the document transaction.');
                }
            } catch (Throwable $rollbackError) {
                throw new VectorStoreException(
                    'Document batch failed; rollback also failed: '.$rollbackError->getMessage(),
                    0,
                    $error,
                );
            }
            throw $error;
        }

        return $this;
    }

    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $compiled = (new ParadeDBFilterCompiler($this->schema))->compile($filters);
        $statement = $this->prepare('DELETE FROM '.$this->tableName.' WHERE collection_id = :collection_id AND '.$compiled['sql']);
        $this->executeStatement($statement, [':collection_id' => $this->collectionId ?? ''] + $compiled['bindings']);

        return $this;
    }

    /** @deprecated Use delete() instead. */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    /** @param string|array<string, string> $sourceType */
    public function deleteBy(string|array $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        if (is_array($sourceType)) {
            if (func_num_args() > 1 || $sourceType === []) {
                throw new InvalidArgumentException('Array filters must be nonempty and cannot have a second argument.');
            }
            $filters = $sourceType;
        } else {
            $filters = ['source_type' => $sourceType];
            if ($sourceName !== null) {
                $filters['source_name'] = $sourceName;
            }
        }

        $conditions = ['collection_id = :collection_id'];
        $parameters = [':collection_id' => $this->collectionId ?? ''];
        foreach ($filters as $column => $value) {
            if (!in_array($column, ['source_type', 'source_name'], true) || !is_string($value)) {
                throw new InvalidArgumentException('Only string source_type and source_name filters are supported.');
            }
            $conditions[] = '"'.$column.'" = :'.$column;
            $parameters[':'.$column] = $value;
        }
        $statement = $this->prepare('DELETE FROM '.$this->tableName.' WHERE '.implode(' AND ', $conditions));
        $this->executeStatement($statement, $parameters);

        return $this;
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): array
    {
        return $this->search(new SearchRequest($embedding));
    }

    /** @return Document[] */
    public function search(SearchRequest $request): array
    {
        $vector = $this->encodeEmbedding($request->embedding);
        $compiled = (new ParadeDBFilterCompiler($this->schema))->compile($request->filters);
        $statement = $this->prepare(sprintf(
            <<<'SQL'
                SELECT id, content, source_type, source_name, metadata, embedding,
                       embedding <=> CAST(:embedding AS vector) AS distance
                FROM %s
                WHERE collection_id = :collection_id AND %s
                ORDER BY distance
                LIMIT %d
                SQL,
            $this->tableName,
            $compiled['sql'],
            $request->topK ?? $this->topK,
        ));
        $this->executeStatement($statement, [':embedding' => $vector, ':collection_id' => $this->collectionId ?? ''] + $compiled['bindings']);

        return $this->hydrateDocuments($statement, true);
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function hybridSearch(string $query, array $embedding, ?FilterExpression $filters = null, ?int $topK = null): array
    {
        $request = new SearchRequest($embedding, $filters, $topK);
        $topK = $request->topK ?? $this->topK;
        $vector = $this->encodeEmbedding($embedding);
        $compiler = new ParadeDBFilterCompiler($this->schema);
        $vectorFilters = $compiler->compile($filters, 'vf');
        $bm25Filters = $compiler->compile($filters, 'bf');
        $candidateLimit = $topK > intdiv(PHP_INT_MAX, 2) ? PHP_INT_MAX : $topK * 2;
        $statement = $this->prepare(sprintf(
            <<<'SQL'
                WITH vector_results AS (
                    SELECT search_id, ROW_NUMBER() OVER (ORDER BY embedding <=> CAST(:embedding AS vector)) AS rank
                    FROM %1$s
                    WHERE collection_id = :vector_collection_id AND %5$s
                    ORDER BY embedding <=> CAST(:ordered_embedding AS vector)
                    LIMIT %2$d
                ),
                bm25_results AS (
                    SELECT search_id, ROW_NUMBER() OVER (ORDER BY pdb.score(search_id) DESC) AS rank
                    FROM %1$s
                    WHERE content ||| :query AND collection_id = :bm25_collection_id AND %6$s
                    ORDER BY pdb.score(search_id) DESC
                    LIMIT %2$d
                ),
                fused AS (
                    SELECT search_id, SUM(score) AS score
                    FROM (
                        SELECT search_id, 1.0 / (%3$d::numeric + rank) AS score FROM vector_results
                        UNION ALL
                        SELECT search_id, 1.0 / (%3$d::numeric + rank) AS score FROM bm25_results
                    ) rankings
                    GROUP BY search_id
                )
                SELECT documents.id, documents.content, documents.source_type,
                       documents.source_name, documents.metadata, documents.embedding, fused.score
                FROM fused
                JOIN %1$s documents ON documents.search_id = fused.search_id
                ORDER BY fused.score DESC, documents.id
                LIMIT %4$d
                SQL,
            $this->tableName,
            $candidateLimit,
            $this->rrfK,
            $topK,
            $vectorFilters['sql'],
            $bm25Filters['sql'],
        ));
        $this->executeStatement($statement, [
            ':embedding' => $vector,
            ':ordered_embedding' => $vector,
            ':query' => $query,
            ':vector_collection_id' => $this->collectionId ?? '',
            ':bm25_collection_id' => $this->collectionId ?? '',
        ] + $vectorFilters['bindings'] + $bm25Filters['bindings']);

        return $this->hydrateDocuments($statement);
    }

    /** @return Document[] */
    protected function hydrateDocuments(PDOStatement $statement, bool $distanceScore = false): array
    {
        $documents = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $document = new Document((string) $row['content']);
            $document->setId((string) $row['id']);
            $document->setSourceType((string) $row['source_type']);
            $document->setSourceName((string) $row['source_name']);
            $document->setScore($distanceScore
                ? VectorSimilarity::similarityFromDistance((float) $row['distance'])
                : (float) $row['score']);
            $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
            $embedding = json_decode((string) $row['embedding'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || !is_array($embedding)) {
                throw new VectorStoreException('Stored metadata and embedding must decode to arrays.');
            }
            $this->encodeEmbedding($embedding);
            $document->setMetadata($metadata);
            $document->setEmbedding($embedding);
            $documents[] = $document;
        }

        return $documents;
    }

    /** @param array<mixed> $embedding */
    protected function encodeEmbedding(array $embedding): string
    {
        if (!array_is_list($embedding) || count($embedding) !== $this->dimensions) {
            throw new VectorStoreException('Embedding must be a list with exactly '.$this->dimensions.' dimensions.');
        }
        $squaredNorm = 0.0;
        foreach ($embedding as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new VectorStoreException('Embedding components must be finite numbers.');
            }
            // pgvector stores float32 values, which can overflow or underflow PHP doubles.
            $stored = unpack('fvalue', pack('f', (float) $value))['value'];
            if (!is_finite($stored)) {
                throw new VectorStoreException('Embedding components must fit in a pgvector float32.');
            }
            $square = unpack('fvalue', pack('f', $stored * $stored))['value'];
            $squaredNorm = unpack('fvalue', pack('f', $squaredNorm + $square))['value'];
        }
        // Cosine accumulation also uses float32, not just the stored components.
        if (!is_finite($squaredNorm) || $squaredNorm <= 0.0) {
            throw new VectorStoreException('Cosine similarity requires a finite, nonzero float32 squared norm.');
        }

        return json_encode($embedding, JSON_THROW_ON_ERROR);
    }

    protected function validateSchema(): void
    {
        $statement = $this->prepare(
            'SELECT c.oid, c.relkind FROM pg_catalog.pg_class c WHERE c.oid = pg_catalog.to_regclass(:table_name)',
        );
        $this->executeStatement($statement, [':table_name' => $this->tableName]);
        $table = $statement->fetch(PDO::FETCH_ASSOC);
        if ($table === false) {
            return;
        }
        if (!in_array($table['relkind'], ['r', 'p'], true)) {
            throw new VectorStoreException('The configured relation must be a table.');
        }
        $statement = $this->prepare(<<<'SQL'
            SELECT a.attname, t.typname, a.atttypmod, a.attnotnull, a.attidentity,
                   EXISTS (
                       SELECT 1 FROM pg_catalog.pg_index i
                       WHERE i.indrelid = a.attrelid AND i.indisprimary AND i.indimmediate
                         AND i.indnkeyatts = 2 AND i.indkey[0] = a.attnum
                         AND i.indkey[1] = (SELECT attnum FROM pg_catalog.pg_attribute
                             WHERE attrelid = a.attrelid AND attname = 'id' AND NOT attisdropped)
                   ) AS primary_key,
                   EXISTS (
                       SELECT 1 FROM pg_catalog.pg_index i
                       WHERE i.indrelid = a.attrelid AND i.indisunique AND i.indisvalid AND i.indimmediate
                         AND i.indnkeyatts = 1 AND i.indkey[0] = a.attnum
                         AND i.indpred IS NULL AND i.indexprs IS NULL
                   ) AS unique_key
            FROM pg_catalog.pg_attribute a
            JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
            WHERE a.attrelid = :oid AND a.attnum > 0 AND NOT a.attisdropped
            SQL);
        $this->executeStatement($statement, [':oid' => $table['oid']]);
        $columns = [];
        while ($column = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns[$column['attname']] = $column;
        }
        foreach (['collection_id' => ['text'], 'search_id' => ['int8'], 'id' => ['text'], 'content' => ['text'], 'source_type' => ['text', 'varchar'],
            'source_name' => ['text', 'varchar'], 'metadata' => ['jsonb'], 'embedding' => ['vector']] as $name => $types) {
            $column = $columns[$name] ?? null;
            if ($column === null || !in_array($column['typname'], $types, true)
                || !in_array($column['attnotnull'], [true, 1, '1', 't'], true)) {
                throw new VectorStoreException('Schema migration required: incompatible or missing table column: '.$name);
            }
        }
        if ((int) $columns['embedding']['atttypmod'] !== $this->dimensions) {
            throw new VectorStoreException('Existing embedding dimension does not match '.$this->dimensions.'.');
        }
        if (!in_array($columns['collection_id']['primary_key'], [true, 1, '1', 't'], true)) {
            throw new VectorStoreException('Schema migration required: primary key must be non-deferrable (collection_id, id).');
        }
        if ($columns['search_id']['attidentity'] !== 'a'
            || !in_array($columns['search_id']['unique_key'], [true, 1, '1', 't'], true)) {
            throw new VectorStoreException('Schema migration required: search_id must be GENERATED ALWAYS AS IDENTITY and globally unique.');
        }
        // Resolve the expected index in the table's schema, not independently through search_path.
        $statement = $this->prepare(<<<'SQL'
            SELECT am.amname, i.indisvalid, i.indpred IS NULL AS unfiltered,
                   i.indrelid = t.oid AS correct_table,
                   pg_catalog.pg_get_indexdef(c.oid, 1, true) AS first_column,
                   pg_catalog.pg_get_indexdef(c.oid, 2, true) AS second_column,
                   i.indnkeyatts,
                   EXISTS (SELECT 1 FROM pg_catalog.pg_options_to_table(c.reloptions)
                           WHERE option_name = 'key_field' AND option_value = 'search_id') AS correct_key
            FROM pg_catalog.pg_class t
            JOIN pg_catalog.pg_class c ON c.relnamespace = t.relnamespace AND c.relname = :index_name
            LEFT JOIN pg_catalog.pg_index i ON i.indexrelid = c.oid
            LEFT JOIN pg_catalog.pg_am am ON am.oid = c.relam
            WHERE t.oid = :oid
            SQL);
        $this->executeStatement($statement, [':oid' => $table['oid'], ':index_name' => trim($this->bm25IndexName, '"')]);
        $index = $statement->fetch(PDO::FETCH_ASSOC);
        if ($index !== false && ($index['amname'] !== 'bm25'
            || (int) $index['indnkeyatts'] !== 2 || $index['first_column'] !== 'search_id' || $index['second_column'] !== 'content'
            || !in_array($index['indisvalid'], [true, 1, '1', 't'], true)
            || !in_array($index['unfiltered'], [true, 1, '1', 't'], true)
            || !in_array($index['correct_table'], [true, 1, '1', 't'], true)
            || !in_array($index['correct_key'], [true, 1, '1', 't'], true))) {
            throw new VectorStoreException('Schema migration required: incompatible BM25 index; expected (search_id, content) with key_field search_id.');
        }
    }

    protected function executeSql(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new VectorStoreException('Database operation failed: '.($this->pdo->errorInfo()[2] ?? 'unknown error'));
        }
    }

    protected function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new VectorStoreException('Database preparation failed: '.($this->pdo->errorInfo()[2] ?? 'unknown error'));
        }

        return $statement;
    }

    /** @param array<string, mixed> $parameters */
    protected function executeStatement(PDOStatement $statement, array $parameters): void
    {
        if (!$statement->execute($parameters)) {
            throw new VectorStoreException('Database execution failed: '.($statement->errorInfo()[2] ?? 'unknown error'));
        }
    }
}
