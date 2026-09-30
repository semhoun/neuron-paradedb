# ParadeDB for Neuron AI

**English** | [Français](README.fr.md)

> Vector similarity and BM25 hybrid search in PostgreSQL for PHP RAG applications using Neuron AI.

## Overview

`semhoun/neuron-paradedb` connects [Neuron AI](https://github.com/neuron-core/neuron-ai) to ParadeDB through PDO. It combines cosine-distance semantic search and BM25 lexical search using Reciprocal Rank Fusion (RRF). Documents, embeddings and metadata share one table, without a separate search service or PHP ParadeDB client.

Collections separate documents within that table. Neuron AI's typed filters apply to searches and deletions.

## Technologies and Compatibility

| Layer | Technology |
| --- | --- |
| Language | PHP `^8.1`, extensions `pdo`, `pdo_pgsql` and `curl` for Neuron |
| RAG integration | Neuron AI `^4.0` |
| Storage and search | ParadeDB `0.25.9-pg18`, PostgreSQL 18, extensions `vector` and `pg_search` |
| Data access | PDO PostgreSQL, bound parameters, JSONB metadata |
| Quality | PHPUnit, PHPStan level 6, PHP CS Fixer |
| Continuous integration | GitHub Actions; Docker Compose test database |

Since version 3.0.0, the package's major version follows Neuron AI's major version. Minor and patch versions remain independent.

| Package | Neuron AI | Branch |
| --- | --- | --- |
| 3.x | `^3.0` | `3.x` |
| 4.x | `^4.0` | `main` |

The examples below target Neuron AI 4. Package 4.x does not support Neuron AI 3.

## Architecture

`HybridRetrieval` embeds a message's text, combines mandatory filters with AND, then calls the hybrid store. `ParadeDBVectorStore` validates documents and delegates filter compilation to `ParadeDBFilterCompiler` before executing PDO queries.

- The HNSW index uses cosine distance; the BM25 index covers content and the technical key `search_id`.
- Each hybrid branch selects at most `2 * topK` candidates, then RRF keeps the best `topK` results.
- Collections and filters apply before ranking and limits in both branches.
- `HybridVectorStoreInterface` extends Neuron's interface with `hybridSearch(string $query, array $embedding, ?FilterExpression $filters = null, ?int $topK = null): iterable`.
- `HybridRetrieval::retrieve()` materializes results into an array, including results from custom lazy stores.

```text
src/
  Retrieval/       # Hybrid retrieval strategy for Neuron AI
  VectorStore/     # PDO store, hybrid contract and filter compilation
tests/
  Unit/            # Validation and contracts without a database
  Integration/     # Queries, migrations and permissions on ParadeDB
.github/workflows/ # PHP matrix and integration tests
compose.yaml       # Local ParadeDB database for tests only
```

## Installation

### Prerequisites

- PHP 8.1 or newer in the 8.x series, with the extensions listed above.
- Composer 2, as used in CI.
- A ParadeDB server with `vector` and `pg_search` installed; PostgreSQL with pgvector alone is insufficient.
- An embeddings provider for ingestion and queries; the examples use OpenAI, but it is not required.
- Docker with Compose for local integration tests only.

From your consuming application:

```bash
composer require semhoun/neuron-paradedb:^4.0
```

### Configuration

The library receives a PDO connection and does not load `.env` files. Supply these variables to the PHP process to use the examples:

| Variable | Purpose |
| --- | --- |
| `PARADEDB_DSN` | PDO PostgreSQL DSN for the target database |
| `PARADEDB_USER` | PostgreSQL role |
| `PARADEDB_PASSWORD` | Password, supplied outside the repository |
| `OPENAI_API_KEY` | OpenAI provider key, only for this example |
| `PARADEDB_PORT` | Local Compose port, defaults to `55432` |

### Initialize the Store

Run provisioning explicitly with an administrative role, not on every application request:

```php
require 'vendor/autoload.php';

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
    collectionId: null,
);
$store->setupDatabase();
```

The constructor enables PDO exceptions and checks an existing table for compatibility without creating objects. `setupDatabase()` creates missing extensions, the table and indexes; it is not a migration tool. An incompatible embedding dimension is rejected.

Table names must be single ASCII identifiers of at most 48 characters, without schema qualification. Letters and underscores are allowed initially, followed by letters, underscores or digits. Use a trusted, fixed `search_path`.

### Ingest and Search

The following blocks reuse the store initialized above. Use embeddings with the same dimension and model for ingestion and retrieval.

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\SearchRequest;
use Semhoun\NeuronParadeDB\Retrieval\HybridRetrieval;

$embeddings = new OpenAIEmbeddingsProvider(
    key: getenv('OPENAI_API_KEY'),
    model: 'text-embedding-3-small',
    dimensions: 1536,
);
$text = 'ZXQ-991 is a compact industrial sensor.';
$document = (new Document($text))
    ->setId('sensor-1')
    ->setSourceType('manual')
    ->setSourceName('sensor-guide')
    ->setEmbedding($embeddings->embedText($text));
$store->addDocument($document);

$retrieval = new HybridRetrieval($store, $embeddings);
$documents = $retrieval->retrieve(new UserMessage('ZXQ-991 sensor'));

// Vector-only search; topK overrides the default for this call.
$documents = $store->search(new SearchRequest(
    embedding: $embeddings->embedText('compact sensor'),
    topK: 8,
));
```

For an existing agent extending `NeuronAI\RAG\RAG`, with its AI provider already configured:

```php
$agent->setVectorStore($store);
$agent->setEmbeddingsProvider($embeddings);
$agent->setRetrieval(new HybridRetrieval($store, $embeddings));
$agent->addDocuments([new Document('Maintenance guide for the ZXQ-991 sensor.')]);
```

This repository has no application server or startup command: these components run within your PHP application.

## Collections and Filters

```php
$teamStore = $store->forCollection('team-a');
$otherTeam = $store->forCollection('team-b');
$uncollected = $store->forCollection(null);
$query = 'ZXQ-991 sensor';
$documents = $teamStore->hybridSearch($query, $embeddings->embedText($query));
```

`forCollection()` returns a clone sharing PDO, the schema and options, without executing SQL or mutating the original. Pass the same collection-scoped store to the agent and its retrieval strategy.

- `null` selects only documents without a collection, never all collections.
- Collection IDs are nonempty strings without normalization; `''` is reserved in SQL for the uncollected scope.
- The primary key `(collection_id, id)` allows the same document ID across collections. `search_id` remains a globally unique internal BM25 key.
- Metadata keys `ragId` and `collection_id` never change the scope. There is no all-collections search or deletion mode.
- This application-level separation is neither authorization nor PostgreSQL RLS. BM25 statistics remain shared across the table.
- **`dropTable()` deletes the entire table and its indexes, even from a collection clone.**

Declare filterable metadata in a schema. Native fields `sourceType` and `sourceName` are also available:

```php
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;

$typedStore = new ParadeDBVectorStore(
    pdo: $pdo,
    dimensions: 1536,
    collectionId: 'team-a',
    schema: DocumentSchema::of(
        DocumentField::string('tenant')->required()->filterable(),
        DocumentField::integer('year')->filterable(),
        DocumentField::strings('tags')->filterable(),
    ),
);
$document->setMetadata(['tenant' => 'team-a', 'year' => 2026, 'tags' => ['sensor']]);
$typedStore->addDocument($document);
$filters = Filter::where('tenant', 'team-a')->whereGreaterThanOrEqual('year', 2025);
$documents = $typedStore->hybridSearch($query, $embeddings->embedText($query), $filters, 4);
$retrieval = new HybridRetrieval($typedStore, $embeddings, filters: $filters);
$documents = $retrieval->retrieve(new UserMessage($query), Filter::containsAny('tags', ['sensor']));
$typedStore->delete(Filter::where('sourceType', 'manual')->where('sourceName', 'sensor-guide'));
```

The optional schema defaults to `DocumentSchema::default()`. Metadata remains stored as JSON; only declared filterable fields and native fields can be filtered. Use `Document` getters/setters, not public properties.

Operators: `eq`, `neq`, `in`, numeric `gt/gte/lt/lte`, `containsAny/containsAll` for string arrays, and `FilterGroup::allOf/anyOf` groups. `neq` requires a required field. Null comparisons, undeclared fields, incompatible values and raw filters are rejected before execution.

Values and metadata keys are bound parameters; JSONB preserves scalar types. The collection constraint, strategy filters and per-call filters are combined with AND. An OR group cannot widen those scopes. Calls do not mutate store defaults.

The schema validates new documents but does not migrate or revalidate historical metadata. Audit existing data before adding a required field or changing its type.

## Writes and Scores

- `addDocument()` and `addDocuments()` require precomputed embeddings. An existing ID within the collection produces an upsert preserving `search_id`.
- Batches are validated before writing and processed atomically. An existing transaction uses a savepoint without committing the caller's work. Empty batches are no-ops; this is not a PostgreSQL COPY loader.
- Vectors must be finite, nonzero and of the exact dimension, with a finite, nonzero float32 squared norm. Unsafe magnitudes are rejected without normalization. HNSW `vector` indexes support up to 2,000 dimensions.
- Results contain a string ID, content, embedding, sources, metadata and score. Vector scores use Neuron's cosine conversion; hybrid scores sum `1 / (rrfK + rank)` per branch and are not probabilities.
- `dimensions`, `topK` and `rrfK` must be positive. A larger `rrfK` reduces the relative weight of top-ranked positions. HNSW remains approximate.
- Legacy helpers `similaritySearch($embedding)`, `deleteBy($type, $name)` and `deleteBy(array)` remain adapter-specific. The array must contain nonempty source constraints with string values, combined with AND. `deleteBySource()` is deprecated; no Neuron 3 compatibility layer is loaded.

## Provisioning and Migration

### Production Permissions

`setupDatabase()` runs `CREATE EXTENSION IF NOT EXISTS` for `vector` and `pg_search`, then creates the table and indexes. Use a provisioning role permitted to create those extensions, create objects in the schema and manage the table's indexes. The extensions must be installed on the server.

After provisioning, a separate, existing application role, named `neuron_app` here, needs only:

```sql
GRANT USAGE ON SCHEMA public TO neuron_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.rag_documents TO neuron_app;
```

Integration tests verify writes, searches and deletions without identity-sequence privileges on the pinned ParadeDB image. This applies to `GENERATED ALWAYS AS IDENTITY`, not arbitrary sequences or `SERIAL`. Do not call `setupDatabase()` with the restricted role; permission errors are propagated.

The repository provides no application deployment or production compilation step. `compose.yaml` is for testing only. Keep PostgreSQL credentials out of source code.

### Legacy 1.0 Tables

The schema introduced in 1.1 is required, even without named collections. The constructor and `setupDatabase()` reject legacy tables without migrating them. Run this static method **before constructing the store**, using a table-owner connection and the existing dimension:

```php
ParadeDBVectorStore::upgradeToV11(pdo: $pdo, dimensions: 1536, tableName: 'rag_documents');
```

The migration adds `collection_id TEXT NOT NULL DEFAULT ''`, replaces the primary key with `(collection_id, id)`, adds a unique `search_id BIGINT GENERATED ALWAYS AS IDENTITY` and rebuilds BM25 on `(search_id, content)`. Documents, metadata and the HNSW index are preserved; existing documents belong to the `null` scope.

The operation is transactional with an exclusive lock, or uses a savepoint within an existing transaction. Locks can then last until that transaction ends. Errors roll back changes; external dependencies are never removed with `CASCADE`. An already migrated table remains unchanged except for a missing BM25 index being created. Missing or partially migrated tables are rejected.

Back up the database, test migration and rollback on a copy, then schedule maintenance without application access. Do not mix versions 1.0 and 1.1 against the same table or run this migration on every request. New installations use `setupDatabase()` directly.

Moving from package 3.x to 4.x requires updating Neuron and `Document` access, but no new physical migration.

## Development and Testing

```bash
git clone https://github.com/semhoun/neuron-paradedb.git
cd neuron-paradedb
composer install
composer validate --strict
composer test
composer analyse
composer style:check
```

The three unit test files cover validation, filter compilation and hybrid retrieval without a database or API key. The integration test file covers schema, scores, collections, filters, upserts, transactions/savepoints, migrations and restricted permissions.

For local integration tests, use only a disposable database with administrative privileges. Compose exposes it on localhost. Export `PARADEDB_USER` and `PARADEDB_PASSWORD` using the test values defined in `compose.yaml`, then run:

```bash
docker compose up -d --wait
export PARADEDB_DSN="pgsql:host=127.0.0.1;port=${PARADEDB_PORT:-55432};dbname=neuron_paradedb_test"
composer test:integration
docker compose down -v
```

Tests create and remove temporary tables and a restricted role. Without `PARADEDB_DSN`, they are skipped. The final command removes the containers and associated volumes.

[CI](.github/workflows/tests.yml) configures PHP 8.1 through 8.5, including a PHP 8.1 job with lowest dependencies. Integration uses PHP 8.3 and `paradedb/paradedb:0.25.9-pg18`. Previously recorded local validation used PHP 8.5.10, Neuron AI 4.0.0, PostgreSQL 18.6 and pgvector 0.8.4. Later ParadeDB versions are not yet qualified.

## Contributing

1. Fork the repository and create a dedicated branch.
2. Add relevant tests in `tests/Unit` or `tests/Integration`.
3. Run the validation commands above, and integration tests for SQL changes.
4. Use `composer style` to apply formatting, then open a pull request describing the change.

## License

[MIT](LICENSE). Copyright 2026 Nathanael (semhoun).
