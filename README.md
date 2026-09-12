# ParadeDB for Neuron AI

Vector similarity and BM25 hybrid retrieval for [Neuron AI](https://github.com/neuron-core/neuron-ai), packaged independently from the core. Hybrid search combines cosine and lexical rankings with Reciprocal Rank Fusion (RRF), without a separate search service or PHP ParadeDB client.

## Requirements

- PHP 8.1 or newer (8.x), with `pdo` and `pdo_pgsql`.
- Neuron AI 3.x.
- ParadeDB **0.25.9 / PostgreSQL 18**, including `pg_search` and `vector`. Plain PostgreSQL with pgvector alone is not sufficient.
- Nonzero, finite embeddings with the same dimension and model for ingestion and queries. HNSW `vector` indexes support up to 2,000 dimensions.

```sh
composer require semhoun/neuron-paradedb
```

## Create the Store

```php
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBVectorStore;

$pdo = new PDO(
    getenv('PARADEDB_DSN'),
    getenv('PARADEDB_USER'),
    getenv('PARADEDB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$store = new ParadeDBVectorStore(
    pdo: $pdo,
    dimensions: 1536,
    tableName: 'rag_documents',
    topK: 4,
    rrfK: 60,
);

// Explicit provisioning step, not something to run on every request.
$store->setupDatabase();
```

The constructor enables PDO exception mode and checks an existing table for compatibility. It does not create extensions, tables or indexes. `setupDatabase()` creates the table and HNSW/BM25 indexes idempotently, and rejects an incompatible existing embedding dimension rather than silently reusing it. It is not a schema migration tool.

Table names are single PostgreSQL identifiers (letters, digits and underscores, not starting with a digit), at most 48 characters; schema-qualified names are not accepted. Use a trusted, fixed PostgreSQL `search_path`. Values are bound parameters, including query text and source filters.

## Connect a RAG Agent

Use the same store and embeddings provider for ingestion and retrieval. Here `$agent` is your configured instance of a class extending `NeuronAI\RAG\RAG`, with its AI provider already configured:

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use Semhoun\NeuronParadeDB\Retrieval\HybridRetrieval;

$embeddings = new OpenAIEmbeddingsProvider(
    key: getenv('OPENAI_API_KEY'),
    model: 'text-embedding-3-small',
    dimensions: 1536,
);

$agent->setVectorStore($store);
$agent->setEmbeddingsProvider($embeddings);
$agent->setRetrieval(new HybridRetrieval($store, $embeddings));
$agent->addDocuments([new Document('ZXQ-991 is our compact industrial sensor.')]);
```

For standalone retrieval:

```php
use NeuronAI\Chat\Messages\UserMessage;

$retrieval = new HybridRetrieval($store, $embeddings);
$documents = $retrieval->retrieve(new UserMessage('ZXQ-991 sensor'));

// Or vector-only search:
$documents = $store->similaritySearch($embeddings->embedText('compact sensor'));
```

`HybridVectorStoreInterface` extends Neuron's `VectorStoreInterface`; its `hybridSearch(string $query, array $embedding): iterable` also permits custom lazy stores. `HybridRetrieval` materializes the results into the array expected by Neuron.

## Writes, Deletes and Scores

`addDocument()` and `addDocuments()` require documents with embeddings already populated. Reusing a document ID updates all stored fields. A batch is atomic: SQL, embedding or JSON failures roll back the whole batch. In an existing caller transaction, the store uses a savepoint and never commits the caller's work. An empty batch is a no-op. These are transactional per-document upserts, not a PostgreSQL COPY bulk loader.

Results include the ID (as a string), content, embedding, source type/name, metadata and score. Vector scores use Neuron's cosine-distance conversion; hybrid scores are RRF values, not cosine similarity or probabilities. RRF sums `1 / (rrfK + rank)` across the two candidate lists, each limited to `2 * topK`. Final results are limited to `topK`. Larger `rrfK` reduces the relative weight of top-ranked positions. All three numeric configuration values must be positive.

```php
$store->deleteBy('web', 'https://example.com/page');
$store->deleteBy('web'); // All documents of this source type.
$store->deleteBy(['source_type' => 'file', 'source_name' => 'manual.pdf']);
```

Array filters are combined with AND and accept only string-valued `source_type` and `source_name`. Empty or unknown filters are rejected to prevent accidental mass deletion. The string form matches Neuron 3.x's actual `DeleteByInterface`. `deleteBySource($type, $name)` remains available but deprecated. `dropTable()` permanently deletes the entire table and its indexes; reserve it for tests or deliberate maintenance.

## Database Permissions

`setupDatabase()` executes these statements and therefore normally requires an administrator/provisioning connection:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_search;
```

The extensions must already be installed on the PostgreSQL server. Have an administrator run the above and provision the table/indexes using `setupDatabase()` with the desired dimension. The provisioning role needs CREATE on the target schema and ownership of the table to create its indexes. A separate runtime role only needs schema USAGE and SELECT, INSERT, UPDATE, DELETE on the provisioned table:

```sql
GRANT USAGE ON SCHEMA public TO neuron_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.rag_documents TO neuron_app;
```

Do not call `setupDatabase()` from the restricted runtime connection. Permission errors are propagated explicitly. Keep PostgreSQL credentials in your environment, never in source control.

## Development

```sh
composer install
composer validate --strict
composer test
composer analyse
composer style:check
docker compose up -d --wait
PARADEDB_DSN='pgsql:host=127.0.0.1;port=55432;dbname=neuron_paradedb_test' \
PARADEDB_USER=neuron_test PARADEDB_PASSWORD=neuron_test composer test:integration
docker compose down -v
```

The Compose database is test-only, bound to localhost; override `PARADEDB_PORT` if needed. Integration tests use their own temporary tables and are skipped when `PARADEDB_DSN` is unset. Unit tests require no database or external API keys.

CI covers PHP 8.1 through 8.5, latest compatible dependencies and a lowest-dependency PHP 8.1 job. A separate integration job runs against the pinned `paradedb/paradedb:0.25.9-pg18` image. Later ParadeDB releases are not yet qualified.

## Origin and License

Extracted from [neuron-core/neuron-ai#639](https://github.com/neuron-core/neuron-ai/pull/639) following the maintainer's preference for external integrations. All package classes use `Semhoun\NeuronParadeDB\`; no aliases or modifications are added to the Neuron core. MIT licensed.
