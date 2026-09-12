<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\VectorStore;

use InvalidArgumentException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PDO;
use PDOStatement;
use Throwable;

class ParadeDBVectorStore implements HybridVectorStoreInterface
{
    protected readonly string $tableName;
    protected readonly string $bm25IndexName;
    protected readonly string $vectorIndexName;

    public function __construct(
        protected PDO $pdo,
        protected readonly int $dimensions = 1536,
        string $tableName = 'rag_documents',
        protected readonly int $topK = 4,
        protected readonly int $rrfK = 60,
    ) {
        if ($dimensions < 1 || $topK < 1 || $rrfK < 1) {
            throw new InvalidArgumentException('Dimensions, topK and rrfK must be positive integers.');
        }
        if (strlen($tableName) > 48 || preg_match('/^[a-z_][a-z0-9_]*$/iD', $tableName) !== 1) {
            throw new InvalidArgumentException('Table names must be SQL identifiers of at most 48 ASCII characters.');
        }

        $this->tableName = '"'.$tableName.'"';
        $this->bm25IndexName = '"'.$tableName.'_bm25_idx"';
        $this->vectorIndexName = '"'.$tableName.'_embedding_idx"';
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->validateSchema();
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
                    id TEXT PRIMARY KEY,
                    content TEXT NOT NULL,
                    source_type VARCHAR(255) NOT NULL,
                    source_name VARCHAR(255) NOT NULL,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding VECTOR(%d) NOT NULL
                )
                SQL,
            $this->tableName,
            $this->dimensions,
        ));
        $this->validateSchema();
        $this->executeSql(sprintf(
            "CREATE INDEX IF NOT EXISTS %s ON %s USING bm25 (id, content) WITH (key_field = 'id')",
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

        $savepoint = $this->pdo->inTransaction() ? '"neuron_paradedb_'.bin2hex(random_bytes(12)).'"' : null;
        if ($savepoint !== null) {
            $this->executeSql('SAVEPOINT '.$savepoint);
        } elseif (!$this->pdo->beginTransaction()) {
            throw new VectorStoreException('Unable to begin the document transaction.');
        }

        try {
            $statement = $this->prepare(sprintf(
                <<<'SQL'
                    INSERT INTO %s (id, content, source_type, source_name, metadata, embedding)
                    VALUES (:id, :content, :source_type, :source_name, CAST(:metadata AS jsonb), CAST(:embedding AS vector))
                    ON CONFLICT (id) DO UPDATE SET
                        content = EXCLUDED.content,
                        source_type = EXCLUDED.source_type,
                        source_name = EXCLUDED.source_name,
                        metadata = EXCLUDED.metadata,
                        embedding = EXCLUDED.embedding
                    SQL,
                $this->tableName,
            ));
            foreach ($documents as $document) {
                if (!$document instanceof Document) {
                    throw new InvalidArgumentException('Every batch entry must be a Document.');
                }
                $this->executeStatement($statement, [
                    ':id' => (string) $document->getId(),
                    ':content' => $document->getContent(),
                    ':source_type' => $document->getSourceType(),
                    ':source_name' => $document->getSourceName(),
                    ':metadata' => json_encode($document->metadata, JSON_THROW_ON_ERROR),
                    ':embedding' => $this->encodeEmbedding($document->getEmbedding()),
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

    /** @deprecated Use deleteBy() instead. */
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

        $conditions = [];
        $parameters = [];
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
        $vector = $this->encodeEmbedding($embedding);
        $statement = $this->prepare(sprintf(
            <<<'SQL'
                SELECT id, content, source_type, source_name, metadata, embedding,
                       embedding <=> CAST(:embedding AS vector) AS distance
                FROM %s
                ORDER BY distance
                LIMIT %d
                SQL,
            $this->tableName,
            $this->topK,
        ));
        $this->executeStatement($statement, [':embedding' => $vector]);

        return $this->hydrateDocuments($statement, true);
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function hybridSearch(string $query, array $embedding): array
    {
        $vector = $this->encodeEmbedding($embedding);
        $candidateLimit = $this->topK > intdiv(PHP_INT_MAX, 2) ? PHP_INT_MAX : $this->topK * 2;
        $statement = $this->prepare(sprintf(
            <<<'SQL'
                WITH vector_results AS (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY embedding <=> CAST(:embedding AS vector)) AS rank
                    FROM %1$s
                    ORDER BY embedding <=> CAST(:ordered_embedding AS vector)
                    LIMIT %2$d
                ),
                bm25_results AS (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY pdb.score(id) DESC) AS rank
                    FROM %1$s
                    WHERE content ||| :query
                    ORDER BY pdb.score(id) DESC
                    LIMIT %2$d
                ),
                fused AS (
                    SELECT id, SUM(score) AS score
                    FROM (
                        SELECT id, 1.0 / (%3$d::numeric + rank) AS score FROM vector_results
                        UNION ALL
                        SELECT id, 1.0 / (%3$d::numeric + rank) AS score FROM bm25_results
                    ) rankings
                    GROUP BY id
                )
                SELECT documents.id, documents.content, documents.source_type,
                       documents.source_name, documents.metadata, documents.embedding, fused.score
                FROM fused
                JOIN %1$s documents ON documents.id = fused.id
                ORDER BY fused.score DESC, documents.id
                LIMIT %4$d
                SQL,
            $this->tableName,
            $candidateLimit,
            $this->rrfK,
            $this->topK,
        ));
        $this->executeStatement($statement, [':embedding' => $vector, ':ordered_embedding' => $vector, ':query' => $query]);

        return $this->hydrateDocuments($statement);
    }

    /** @return Document[] */
    protected function hydrateDocuments(PDOStatement $statement, bool $distanceScore = false): array
    {
        $documents = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $document = new Document((string) $row['content']);
            $document->id = (string) $row['id'];
            $document->sourceType = (string) $row['source_type'];
            $document->sourceName = (string) $row['source_name'];
            $document->score = $distanceScore
                ? VectorSimilarity::similarityFromDistance((float) $row['distance'])
                : (float) $row['score'];
            $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
            $embedding = json_decode((string) $row['embedding'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || !is_array($embedding)) {
                throw new VectorStoreException('Stored metadata and embedding must decode to arrays.');
            }
            $this->encodeEmbedding($embedding);
            $document->metadata = $metadata;
            $document->embedding = array_map(static fn (int|float $value): float => (float) $value, $embedding);
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
            SELECT a.attname, t.typname, a.atttypmod, a.attnotnull,
                   EXISTS (
                       SELECT 1 FROM pg_catalog.pg_index i
                       WHERE i.indrelid = a.attrelid AND i.indisprimary
                         AND i.indnkeyatts = 1 AND i.indkey[0] = a.attnum
                   ) AS primary_key
            FROM pg_catalog.pg_attribute a
            JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
            WHERE a.attrelid = :oid AND a.attnum > 0 AND NOT a.attisdropped
            SQL);
        $this->executeStatement($statement, [':oid' => $table['oid']]);
        $columns = [];
        while ($column = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns[$column['attname']] = $column;
        }
        foreach (['id' => ['text'], 'content' => ['text'], 'source_type' => ['text', 'varchar'],
            'source_name' => ['text', 'varchar'], 'metadata' => ['jsonb'], 'embedding' => ['vector']] as $name => $types) {
            $column = $columns[$name] ?? null;
            if ($column === null || !in_array($column['typname'], $types, true)
                || !in_array($column['attnotnull'], [true, 1, '1', 't'], true)) {
                throw new VectorStoreException('Incompatible or missing table column: '.$name);
            }
        }
        if ((int) $columns['embedding']['atttypmod'] !== $this->dimensions) {
            throw new VectorStoreException('Existing embedding dimension does not match '.$this->dimensions.'.');
        }
        if (!in_array($columns['id']['primary_key'], [true, 1, '1', 't'], true)) {
            throw new VectorStoreException('The id column must be the single-column primary key.');
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
