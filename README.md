# ParadeDB for Neuron AI

Vector similarity and BM25 hybrid retrieval for [Neuron AI](https://github.com/neuron-core/neuron-ai), packaged independently from the core. Hybrid search combines cosine and lexical rankings with Reciprocal Rank Fusion (RRF), without a separate search service or PHP ParadeDB client. Optional collections separate documents within a shared table while keeping ingestion, retrieval and deletion scoped to the selected collection.

## Requirements

- PHP 8.1 or newer (8.x), with `pdo` and `pdo_pgsql`.
- Neuron AI 3.x.
- ParadeDB **0.25.9 / PostgreSQL 18**, including `pg_search` and `vector`. Plain PostgreSQL with pgvector alone is not sufficient.
- Nonzero, finite embeddings with a finite, nonzero float32 squared norm and the same dimension and model for ingestion and queries. Numerically unsafe magnitudes are rejected rather than silently normalized. HNSW `vector` indexes support up to 2,000 dimensions.

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
    collectionId: null, // Only documents without a collection.
);

// Explicit provisioning step, not something to run on every request.
$store->setupDatabase();
```

The constructor enables PDO exception mode and checks an existing table for compatibility. It does not create extensions, tables or indexes. `setupDatabase()` creates the table and HNSW/BM25 indexes idempotently, and rejects an incompatible existing embedding dimension rather than silently reusing it. It is not a schema migration tool.

**Upgrading from 1.0?** Read [Upgrading to 1.1](#upgrading-to-11) before deploying, even if you do not use named collections.

Table names are single PostgreSQL identifiers (letters, digits and underscores, not starting with a digit), at most 48 characters; schema-qualified names are not accepted. Use a trusted, fixed PostgreSQL `search_path`. Values are bound parameters, including query text and source filters.

## Optional Collections

```php
$team = new ParadeDBVectorStore(
    pdo: $pdo,
    dimensions: 1536,
    collectionId: 'team-a',
);
$otherTeam = $team->forCollection('team-b');
$uncollected = $team->forCollection(null);
```

`forCollection()` returns a distinct clone sharing the PDO connection and all other options, without changing the original or executing SQL. The optional final constructor argument defaults to `null`: this scope sees **only documents without a collection**, never all collections. Explicit collection IDs must be nonempty strings; they are not trimmed or numerically normalized. `''` is rejected by the public API and reserved in SQL for the `null` scope.

All inserts, upserts, searches and deletes are scoped to the store's collection. The primary key is `(collection_id, id)`, so the same document ID can exist independently in multiple collections and in the uncollected scope. The generated, globally unique `search_id` is an internal BM25 key, never a public document ID. Metadata is preserved unchanged: `ragId` and `collection_id` metadata keys do not select or override the scope. `dropTable()` remains a **global administrative operation**, including when called on a collection clone.

Collection filtering happens before candidate ranking and limits in both hybrid branches. This is application-level separation, not authorization or PostgreSQL RLS: callers choose their own collection IDs. HNSW remains approximate, and BM25 statistics are shared across the table rather than independently computed per collection.

| Store scope | Documents accessible through searches and deletes |
| --- | --- |
| Default constructor or `forCollection(null)` | Only documents without a collection |
| `forCollection('team-a')` | Only documents in `team-a` |
| `forCollection('team-b')` | Only documents in `team-b` |

There is no all-collections search or deletion mode. A collection with no documents returns an empty search result; selecting a collection does not create a separate table or index.

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

For collection-scoped retrieval, pass the same scoped store to the agent and its retrieval strategy:

```php
$teamStore = $store->forCollection('team-a');
$agent->setVectorStore($teamStore);
$agent->setRetrieval(new HybridRetrieval($teamStore, $embeddings));

// Direct hybrid search uses the same collection scope.
$query = 'ZXQ-991 sensor';
$documents = $teamStore->hybridSearch($query, $embeddings->embedText($query));
```

## Writes, Deletes and Scores

`addDocument()` and `addDocuments()` require documents with embeddings already populated. Reusing a document ID within the same collection updates its documentary fields while preserving its technical `search_id`. A batch is atomic: SQL, embedding or JSON failures roll back the whole batch. In an existing caller transaction, the store uses a savepoint and never commits the caller's work. An empty batch is a no-op. These are transactional per-document upserts, not a PostgreSQL COPY bulk loader.

Results include the ID (as a string), content, embedding, source type/name, metadata and score. Vector scores use Neuron's cosine-distance conversion; hybrid scores are RRF values, not cosine similarity or probabilities. RRF sums `1 / (rrfK + rank)` across the two candidate lists, each limited to `2 * topK`. Final results are limited to `topK`. Larger `rrfK` reduces the relative weight of top-ranked positions. All three numeric configuration values must be positive.

```php
$store->deleteBy('web', 'https://example.com/page');
$store->deleteBy('web'); // Documents of this source type in this collection only.
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

On the pinned ParadeDB 0.25.9 / PostgreSQL 18 image, the generated identity works with these table privileges alone: no sequence USAGE, SELECT or UPDATE grant is required. Integration tests exercise insert, upsert, both searches and deletion under a restricted runtime role without sequence privileges. This applies to the `GENERATED ALWAYS AS IDENTITY` column used here, not to arbitrary sequences or `SERIAL` defaults.

Do not call `setupDatabase()` from the restricted runtime connection. Permission errors are propagated explicitly. Keep PostgreSQL credentials in your environment, never in source control.

## Upgrading to 1.1

Version 1.1 introduces optional collections through the final `collectionId` constructor argument and `forCollection()`. Existing constructor calls remain valid: omitting the collection selects only documents without a collection. Document IDs, metadata and the public search and deletion APIs retain their existing formats.

**The database schema changes in 1.1, including for applications that do not use named collections.** Tables created by version 1.0 must be adapted before the new code can use them. Neither the constructor nor `setupDatabase()` performs an automatic migration; incompatible tables are rejected explicitly.

The required schema changes are:

- Add `collection_id TEXT NOT NULL DEFAULT ''`; existing documents belong to this uncollected scope, accessed with `collectionId: null`.
- Replace the primary key on `id` with a non-deferrable primary key on `(collection_id, id)`, in that order.
- Add `search_id BIGINT GENERATED ALWAYS AS IDENTITY` with global, non-deferrable uniqueness.
- Rebuild the BM25 index on `(search_id, content)` with `key_field = 'search_id'`. Document fields and the HNSW index can be preserved.

Run the explicit upgrade method with a table-owner/provisioning connection **before constructing the store**. The constructor intentionally rejects the old schema, so the upgrade method is static:

```php
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBVectorStore;

ParadeDBVectorStore::upgradeToV11(
    pdo: $pdo,
    dimensions: 1536, // Must match the existing embedding dimension.
    tableName: 'rag_documents',
);

$store = new ParadeDBVectorStore($pdo, dimensions: 1536, tableName: 'rag_documents');
```

`upgradeToV11()` preserves existing documents and the HNSW index, detects the actual primary-key constraint name, and rebuilds BM25 using the new technical key. It operates on the whole table, not a single collection. Repeating it on an already upgraded table leaves its data and existing indexes unchanged; a missing BM25 index is created. Missing tables and incompatible or partially migrated schemas are rejected; this is not a general-purpose schema repair tool.

The upgrade runs in a transaction under an exclusive table lock. Any failure rolls back its schema and index changes. If the connection already has a transaction, the method uses a savepoint and leaves the caller responsible for committing or rolling back; locks can remain until that transaction ends. External dependencies that prevent replacing the old primary key cause an error rather than being removed with `CASCADE`.

Back up the database, test the upgrade and rollback on a copy, and allow a maintenance window for locking and index reconstruction. Stop application access during the transition and do not run versions 1.0 and 1.1 against the same table concurrently. Do not run this administrative method from a restricted runtime connection or on each request.

For a new installation, `setupDatabase()` creates the 1.1 schema directly; no migration is needed.

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

The Compose database is test-only, bound to localhost; override `PARADEDB_PORT` if needed. Integration tests use their own temporary tables and a temporary restricted role; run them only against a disposable database with administrative privileges, as provided by Compose. They are skipped when `PARADEDB_DSN` is unset. Unit tests require no database or external API keys.

CI covers PHP 8.1 through 8.5, latest compatible dependencies and a lowest-dependency PHP 8.1 job. A separate integration job runs against the pinned `paradedb/paradedb:0.25.9-pg18` image. Later ParadeDB releases are not yet qualified.

Validated compatibility:

| Component | Tested versions |
| --- | --- |
| PHP | 8.1, 8.2, 8.3, 8.4, 8.5 (CI quality/unit tests) |
| Neuron AI | 3.15.27 (lowest resolved dependencies), 3.16.13, 3.17.0 (local validation) |
| ParadeDB / pg_search | 0.25.9 |
| PostgreSQL / pgvector | 18.6 / 0.8.4 |

The collection implementation was also validated locally with PHP 8.5.10 and Neuron AI 3.17.0 against the pinned database image. Integration coverage includes repeated document IDs across collections, filtering before candidate limits, scoped deletes and upserts, identity stability, transaction/savepoint rollback, legacy migration and its rollback, and restricted runtime permissions.

## License

MIT licensed.
