# ParadeDB for Neuron AI

[English](README.md) | **Français**

> Recherche vectorielle et hybride BM25 dans PostgreSQL pour les applications RAG PHP utilisant Neuron AI.

## Présentation

`semhoun/neuron-paradedb` relie [Neuron AI](https://github.com/neuron-core/neuron-ai) à ParadeDB via PDO. La bibliothèque combine recherche sémantique par distance cosinus et recherche lexicale BM25 avec Reciprocal Rank Fusion (RRF). Elle conserve documents, embeddings et métadonnées dans une même table, sans service de recherche séparé ni client PHP ParadeDB.

Les collections permettent de séparer les documents au sein de cette table. Les filtres typés de Neuron AI s'appliquent aux recherches et aux suppressions.

## Technologies et compatibilité

| Couche | Technologie |
| --- | --- |
| Langage | PHP `^8.1`, extensions `pdo`, `pdo_pgsql` et `curl` pour Neuron |
| Intégration RAG | Neuron AI `^4.0` |
| Stockage et recherche | ParadeDB `0.25.9-pg18`, PostgreSQL 18, extensions `vector` et `pg_search` |
| Accès aux données | PDO PostgreSQL, paramètres liés, métadonnées JSONB |
| Qualité | PHPUnit, PHPStan niveau 6, PHP CS Fixer |
| Intégration continue | GitHub Actions ; base de test Docker Compose |

Depuis la version 3.0.0, la version majeure du paquet suit celle de Neuron AI ; les versions mineures et correctives restent indépendantes.

| Paquet | Neuron AI | Branche |
| --- | --- | --- |
| 3.x | `^3.0` | `3.x` |
| 4.x | `^4.0` | `main` |

Les exemples ci-dessous ciblent Neuron AI 4. Le paquet 4.x ne prend pas en charge Neuron AI 3.

## Architecture

`HybridRetrieval` transforme le texte d'un message en embedding, combine les filtres obligatoires avec AND, puis appelle le stockage hybride. `ParadeDBVectorStore` valide les documents et délègue la compilation des filtres à `ParadeDBFilterCompiler` avant d'exécuter les requêtes PDO.

- L'index HNSW utilise la distance cosinus ; l'index BM25 porte sur le contenu et la clé technique `search_id`.
- Chaque branche hybride sélectionne au maximum `2 * topK` candidats, puis RRF conserve les `topK` meilleurs résultats.
- Les collections et filtres s'appliquent avant classement et limitation dans les deux branches.
- `HybridVectorStoreInterface` étend l'interface Neuron avec `hybridSearch(string $query, array $embedding, ?FilterExpression $filters = null, ?int $topK = null): iterable`.
- `HybridRetrieval::retrieve()` matérialise les résultats en tableau, y compris ceux d'un stockage personnalisé paresseux.

```text
src/
  Retrieval/       # Stratégie de récupération hybride pour Neuron AI
  VectorStore/     # Stockage PDO, contrat hybride et compilation des filtres
tests/
  Unit/            # Validation et contrats sans base de données
  Integration/     # Requêtes, migrations et permissions sur ParadeDB
.github/workflows/ # Matrice PHP et tests d'intégration
compose.yaml       # Base ParadeDB locale réservée aux tests
```

## Installation

### Prérequis

- PHP 8.1 ou supérieur dans la série 8.x, avec les extensions indiquées ci-dessus.
- Composer 2, utilisé par la CI.
- Un serveur ParadeDB avec `vector` et `pg_search` installés ; PostgreSQL avec pgvector seul ne suffit pas.
- Un fournisseur d'embeddings pour l'ingestion et les requêtes ; OpenAI est utilisé ici, mais n'est pas imposé.
- Docker avec Compose pour les tests d'intégration locaux uniquement.

Depuis l'application consommatrice :

```bash
composer require semhoun/neuron-paradedb:^4.0
```

### Configuration

La bibliothèque reçoit une connexion PDO et ne charge aucun fichier `.env`. Fournissez les variables suivantes au processus PHP pour utiliser les exemples :

| Variable | Usage |
| --- | --- |
| `PARADEDB_DSN` | DSN PDO PostgreSQL de la base cible |
| `PARADEDB_USER` | Rôle PostgreSQL |
| `PARADEDB_PASSWORD` | Mot de passe, fourni hors du dépôt |
| `OPENAI_API_KEY` | Clé du fournisseur OpenAI, uniquement pour cet exemple |
| `PARADEDB_PORT` | Port local Compose, `55432` par défaut |

### Initialiser le stockage

Exécutez ce provisionnement explicitement avec un rôle administrateur, pas à chaque requête applicative :

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

Le constructeur active les exceptions PDO et vérifie la compatibilité d'une table existante sans créer d'objets. `setupDatabase()` crée les extensions, la table et les index manquants ; ce n'est pas un outil de migration. Une dimension incompatible est rejetée.

Les noms de table sont des identifiants ASCII uniques de 48 caractères maximum, sans qualification de schéma. Lettres et underscores sont acceptés en première position, puis les chiffres également. Utilisez un `search_path` fixe et maîtrisé.

### Ingérer et rechercher

Les blocs suivants réutilisent le stockage initialisé ci-dessus. Les embeddings doivent avoir la même dimension et provenir du même modèle à l'ingestion et à la recherche.

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
$text = 'ZXQ-991 est un capteur industriel compact.';
$document = (new Document($text))
    ->setId('sensor-1')
    ->setSourceType('manual')
    ->setSourceName('sensor-guide')
    ->setEmbedding($embeddings->embedText($text));
$store->addDocument($document);

$retrieval = new HybridRetrieval($store, $embeddings);
$documents = $retrieval->retrieve(new UserMessage('capteur ZXQ-991'));

// Recherche vectorielle seule ; topK remplace la valeur par défaut pour cet appel.
$documents = $store->search(new SearchRequest(
    embedding: $embeddings->embedText('capteur compact'),
    topK: 8,
));
```

Pour un agent existant étendant `NeuronAI\RAG\RAG`, dont le fournisseur IA est déjà configuré :

```php
$agent->setVectorStore($store);
$agent->setEmbeddingsProvider($embeddings);
$agent->setRetrieval(new HybridRetrieval($store, $embeddings));
$agent->addDocuments([new Document('Guide de maintenance du capteur ZXQ-991.')]);
```

Il n'existe ni serveur applicatif ni commande de démarrage dans ce dépôt : ces composants s'exécutent dans votre application PHP.

## Collections et filtres

```php
$teamStore = $store->forCollection('team-a');
$otherTeam = $store->forCollection('team-b');
$uncollected = $store->forCollection(null);
$query = 'capteur ZXQ-991';
$documents = $teamStore->hybridSearch($query, $embeddings->embedText($query));
```

`forCollection()` renvoie un clone partageant PDO, le schéma et les options, sans requête SQL ni mutation de l'original. Passez le même stockage de collection à l'agent et à sa stratégie de récupération.

- `null` sélectionne uniquement les documents sans collection, jamais toutes les collections.
- Les identifiants de collection sont des chaînes non vides, sans normalisation ; `''` est réservé en SQL au périmètre sans collection.
- La clé primaire `(collection_id, id)` autorise le même identifiant documentaire dans plusieurs collections. `search_id` reste une clé BM25 interne globalement unique.
- Les métadonnées `ragId` et `collection_id` ne changent jamais le périmètre. Il n'existe pas de recherche ou suppression globale par collection.
- Cette séparation applicative n'est ni une autorisation ni du PostgreSQL RLS. Les statistiques BM25 restent communes à la table.
- **`dropTable()` supprime toute la table et ses index, même depuis un clone de collection.**

Déclarez les métadonnées filtrables dans un schéma. Les champs natifs `sourceType` et `sourceName` sont également disponibles :

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

Le schéma optionnel vaut `DocumentSchema::default()`. Les métadonnées restent stockées en JSON ; seuls les champs déclarés filtrables et les champs natifs peuvent être filtrés. Utilisez les getters/setters de `Document`, pas des propriétés publiques.

Opérateurs : `eq`, `neq`, `in`, `gt/gte/lt/lte` numériques, `containsAny/containsAll` pour les tableaux de chaînes, et groupes `FilterGroup::allOf/anyOf`. `neq` exige un champ obligatoire. Les comparaisons à `null`, champs non déclarés, valeurs incompatibles et filtres bruts sont rejetés avant exécution.

Les valeurs et clés de métadonnées sont liées comme paramètres ; JSONB préserve les types scalaires. Les filtres du stockage de collection, de la stratégie et de l'appel sont combinés avec AND. Un groupe OR ne peut pas élargir ces périmètres. Les appels ne modifient pas les valeurs par défaut du stockage.

Le schéma valide les nouveaux documents, mais ne migre ni ne revalide les métadonnées historiques. Auditez les données avant d'ajouter un champ obligatoire ou de changer son type.

## Écritures et scores

- `addDocument()` et `addDocuments()` exigent des embeddings déjà calculés. Un identifiant existant dans la collection produit un upsert conservant `search_id`.
- Un lot est validé avant écriture et traité atomiquement. Une transaction existante utilise un savepoint sans commit du travail appelant. Un lot vide ne fait rien ; ce n'est pas un chargement PostgreSQL COPY.
- Les vecteurs doivent être finis, non nuls, de dimension exacte, avec une norme carrée float32 finie et non nulle. Les amplitudes dangereuses sont rejetées sans normalisation. Les index HNSW `vector` acceptent jusqu'à 2 000 dimensions.
- Les résultats contiennent identifiant chaîne, contenu, embedding, sources, métadonnées et score. Le score vectoriel utilise la conversion cosinus de Neuron ; le score hybride additionne `1 / (rrfK + rang)` par branche, sans représenter une probabilité.
- `dimensions`, `topK` et `rrfK` doivent être strictement positifs. Un `rrfK` plus grand réduit le poids relatif des premiers rangs. HNSW reste approximatif.
- Les anciens helpers `similaritySearch($embedding)`, `deleteBy($type, $name)` et `deleteBy(array)` restent propres à cet adaptateur. Le tableau accepte uniquement des contraintes source non vides, à valeurs chaînes, combinées avec AND. `deleteBySource()` est déprécié ; aucune couche Neuron 3 n'est chargée.

## Provisionnement et migration

### Permissions en production

`setupDatabase()` exécute `CREATE EXTENSION IF NOT EXISTS` pour `vector` et `pg_search`, puis crée table et index. Utilisez un rôle de provisionnement autorisé à créer ces extensions, à créer dans le schéma et à gérer les index de la table. Les extensions doivent être installées côté serveur.

Après provisionnement, un rôle applicatif distinct déjà créé, ici `neuron_app`, peut recevoir seulement :

```sql
GRANT USAGE ON SCHEMA public TO neuron_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.rag_documents TO neuron_app;
```

Les tests d'intégration vérifient les écritures, recherches et suppressions sans privilège sur la séquence d'identité avec l'image ParadeDB épinglée. Cela concerne `GENERATED ALWAYS AS IDENTITY`, pas les séquences arbitraires ou `SERIAL`. N'appelez pas `setupDatabase()` avec le rôle restreint ; les erreurs de permissions sont propagées.

Le dépôt ne fournit pas de déploiement applicatif ni de compilation de production. `compose.yaml` est uniquement destiné aux tests. Conservez les identifiants PostgreSQL hors du code source.

### Anciennes tables 1.0

Le schéma introduit en 1.1 est obligatoire, même sans collection nommée. Le constructeur et `setupDatabase()` refusent les anciennes tables sans les migrer. Exécutez cette méthode statique **avant de construire le stockage**, avec une connexion propriétaire et la dimension existante :

```php
ParadeDBVectorStore::upgradeToV11(pdo: $pdo, dimensions: 1536, tableName: 'rag_documents');
```

La migration ajoute `collection_id TEXT NOT NULL DEFAULT ''`, remplace la clé primaire par `(collection_id, id)`, ajoute `search_id BIGINT GENERATED ALWAYS AS IDENTITY` unique et reconstruit BM25 sur `(search_id, content)`. Documents, métadonnées et index HNSW sont conservés ; les anciens documents appartiennent au périmètre `null`.

L'opération est transactionnelle avec verrou exclusif, ou utilise un savepoint dans une transaction existante. Les verrous peuvent alors durer jusqu'à la fin de cette transaction. Toute erreur annule les changements ; aucune dépendance externe n'est supprimée par `CASCADE`. Une table déjà migrée reste inchangée, sauf création d'un BM25 manquant. Les tables absentes ou partiellement migrées sont refusées.

Sauvegardez la base, testez migration et rollback sur une copie, puis prévoyez une maintenance sans accès applicatif. Ne mélangez pas les versions 1.0 et 1.1 sur la même table et ne lancez pas cette migration à chaque requête. Une nouvelle installation utilise directement `setupDatabase()`.

Passer du paquet 3.x à 4.x nécessite d'adapter Neuron et les accès à `Document`, mais pas de nouvelle migration physique.

## Développement et tests

```bash
git clone https://github.com/semhoun/neuron-paradedb.git
cd neuron-paradedb
composer install
composer validate --strict
composer test
composer analyse
composer style:check
```

Les trois fichiers unitaires couvrent validation, compilation des filtres et récupération hybride sans base ni clé API. Le fichier d'intégration couvre schéma, scores, collections, filtres, upserts, transactions/savepoints, migrations et permissions restreintes.

Pour l'intégration locale, utilisez exclusivement une base jetable avec droits administrateur. Compose l'expose sur localhost. Exportez `PARADEDB_USER` et `PARADEDB_PASSWORD` selon les valeurs de test définies dans `compose.yaml`, puis :

```bash
docker compose up -d --wait
export PARADEDB_DSN="pgsql:host=127.0.0.1;port=${PARADEDB_PORT:-55432};dbname=neuron_paradedb_test"
composer test:integration
docker compose down -v
```

Les tests créent et suppriment des tables temporaires ainsi qu'un rôle restreint. Sans `PARADEDB_DSN`, ils sont ignorés. La commande finale supprime les conteneurs et volumes associés.

La [CI](.github/workflows/tests.yml) configure PHP 8.1 à 8.5, dont un job PHP 8.1 avec dépendances minimales. L'intégration utilise PHP 8.3 et `paradedb/paradedb:0.25.9-pg18`. La validation locale précédemment consignée utilisait PHP 8.5.10, Neuron AI 4.0.0, PostgreSQL 18.6 et pgvector 0.8.4. Les versions ultérieures de ParadeDB ne sont pas encore qualifiées.

## Contribution

1. Forkez le dépôt et créez une branche dédiée.
2. Ajoutez les tests pertinents dans `tests/Unit` ou `tests/Integration`.
3. Exécutez les commandes de validation ci-dessus, et l'intégration pour tout changement SQL.
4. Utilisez `composer style` pour appliquer le formatage, puis ouvrez une pull request décrivant le changement.

## Licence

[MIT](LICENSE). Copyright 2026 Nathanael (semhoun).
