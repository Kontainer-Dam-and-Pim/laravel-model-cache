# Laravel Model Cache

A **Redis-based**, fully automated, and scalable query cache layer for Laravel.

[![Tests](https://github.com/Kontainer-Dam-and-Pim/laravel-model-cache/actions/workflows/tests.yml/badge.svg)](https://github.com/Kontainer-Dam-and-Pim/laravel-model-cache/actions/workflows/tests.yml)

## Table of Contents

- [Features](#features)
- [Version Compatibility](#version-compatibility)
- [Architecture](#architecture)
- [Performance](#performance)
- [Why?](#why)
- [Why only Redis?](#why-only-redis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Console Commands](#console-commands)
- [Known Issues and Limitations](#known-issues-and-limitations)
- [Contributing](#contributing)
- [License](#license)

## Features

- 🚀 **Fully automated query caching** - no code changes required after setup  
- 🧩 **Granular invalidation** - automatically invalidates only affected rows or tables  
- 🧠 **Transparent integration** with Eloquent and Query Builder  
- ⚡ **Redis-backed** - in-memory speed, one round trip per cache hit  
- 🧰 **Laravel Debugbar integration** - visualize cache hits, misses, and invalidations  
- 🎛️ **Fine-grained control** - include or exclude tables from caching  
- 🧱 **Connection integration** - DB queries pass through a cache-aware connection subclass transparently

## Version Compatibility

| Laravel |  PHP  |
|:-------:|:-----:|
|  13.x   | 8.3+  |

## Architecture

Model Cache integrates with Laravel’s database layer by registering a cache-aware connection, which returns a custom query builder to intercept and cache SQL operations automatically.

**Query lifecycle:**
1. Intercept query → Reflector analyzes SQL, bindings, and affected tables.  
2. Compute cache key → Based on SQL + parameters + database.  
3. Lookup in Redis → If cached, return immediately.  
4. Execute + store → If not cached, execute query and store result.  
5. Auto-invalidate → On any insert, update, delete, or truncate.  

The result is **automatic, consistent caching** across all database operations.

## Performance

Real-world gains range from **5% to 95%**, depending on how many and how complex your queries are. Typical Laravel apps see **~10–30%** faster responses and a **significant drop in DB load**.

- Large payloads still cost to move and encode, so results larger than `model-cache.max_entry_size` (8 MB by default) are returned but not cached.
- The more redundant and complex the queries per request, the bigger the benefit.
- Reduced database traffic can translate to lower infra cost and easier horizontal scaling.

## Why?

- **Database-heavy apps** (especially with Eloquent) often repeat the same queries and not all are efficient.
- **RDBMS internal caches** (e.g., MySQL Query Cache) have hard limits:
  - Do not cache multi-table queries (joins)
  - **Coarse invalidation** (row change can evict a whole table)
  - **Not distributed** across DB servers
  - **Poor scalability** under load
- **Laravel manual caching** requires manual invalidation or time-based expiry.

Model Cache provides automated, granular, distributed caching with transparent invalidation and scale-out via Redis.

## Why only Redis?

- Requires **in-memory** storage for latency and throughput.
- Must be **easily scalable** and **distributed**.
- Needs **tagging** support for granular invalidation (Laravel Cache tags exist but are slow for this use case).

Therefore, Model Cache builds directly on top of Laravel Redis. If you need another backend, contributions are welcome.

## Installation

Install via Composer:

```bash
composer require kontainer/laravel-model-cache
```

Model Cache registers itself automatically via **Laravel Package Discovery**.

Then, publish the config file:

```bash
php artisan vendor:publish --provider="Kontainer\ModelCache\ModelCacheServiceProvider"
```

Finally, ensure all your Eloquent models include the trait:

```php
use Kontainer\ModelCache\Database\ModelCacheTrait;

class Car extends Model
{
    use ModelCacheTrait;
}
```

> 💡 It’s best to add the trait in a shared `BaseModel` class that all models extend.

## Configuration
After publishing the configuration file (see [Installation](#installation)), you will find a comprehensive config file at `config/model-cache.php`.

This file allows you to fine-tune cache behavior, such as:
- Enabling or disabling Model Cache globally  
- Setting key prefixes and expiration times  
- Defining which tables to include or exclude from caching  
- Enabling Debugbar integration for cache inspection  

The default configuration is already optimized for most Laravel applications, so you typically won’t need to modify it unless you want more granular control.

### Redis connection

Model Cache uses the Redis connection named by `MODEL_CACHE_REDIS_CONNECTION` (`cache` by default). A Redis that stops answering without closing connections stalls every query for as long as the client waits, so give the connection Model Cache uses short timeouts; a dedicated connection keeps them away from the rest of the application:

```php
// config/database.php, inside 'redis'
'model-cache' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_CACHE_DB', '1'),
    'timeout' => 1.0,            // connect, both clients
    'read_timeout' => 1.0,       // phpredis
    'read_write_timeout' => 1.0, // Predis
],
```

```dotenv
MODEL_CACHE_REDIS_CONNECTION=model-cache
```

## Usage

After installation, Model Cache works **automatically**.  
No code changes or caching calls are required - all database queries are transparently cached and invalidated.

You can control global behavior via `.env`:

```env
MODEL_CACHE_ACTIVE=true
MODEL_CACHE_DEBUGBAR=true
```

### Bypassing the cache per query

Use `withoutCache()` to read a single query straight from the database without caching its result:

```php
// Eloquent
$user = User::where('id', $id)->withoutCache()->first();

// Query Builder
$rows = DB::table('users')->where('active', true)->withoutCache()->get();
```

This is useful for freshness-critical reads and diagnostics. Writes always invalidate, with or without `withoutCache()`, so other readers never see rows a write replaced.

### Caching only selected models

By default every query on a caching connection is cached. Set `MODEL_CACHE_SCOPE=models` to cache only reads issued by models that use `ModelCacheTrait`. `DB::table()` reads and models without the trait then always hit the database, while writes from any builder still invalidate, so cached models never go stale through code paths that are not cached themselves.

```env
MODEL_CACHE_SCOPE=models
```

### Schema changes

Cache entries are stored under a random generation id kept in Redis. Every schema change replaces that id, which retires every entry for all hosts sharing the Redis instance in one atomic step: each completed migration, `schema:load`, `migrate:fresh`/`refresh`, raw DDL run through Laravel (`DB::statement('ALTER TABLE ...')`), and `model-cache:flush`. Inside a transaction the retire happens again when the transaction ends, because a transactional schema change only becomes visible at commit. Retiring after a schema change keeps trying Redis for about a second, even while Redis is skipped after a failure. Retired entries are pruned in bounded `SCAN` batches when a migration batch finishes. A query that started before the switch can never store a result that is served afterwards, and because ids are random, an emptied or restarted Redis simply starts a new generation and never revives entries written under an earlier one.

Retiring is event driven only; Model Cache never polls the database or Redis to detect schema changes. The host that runs migrations must therefore have Model Cache active and use the same Redis connection as the hosts serving traffic; a failed retire there is logged as an error. The same holds for every host that writes to a cached database, such as queue workers and schedulers: with Model Cache disabled, a host's writes invalidate nothing. After any period in which a writing host ran with Model Cache disabled, run `php artisan model-cache:flush` (or `model-cache:enable`, which retires the cache as well) once Model Cache is active again.

### Consistency and failure handling

Every tag carries a random version token. A read captures the tokens of its tags before querying the database and stores its result under their fingerprint; a write replaces the tokens of the tags it touches. A result read before a concurrent write therefore becomes unreachable instead of stale, and an evicted token only causes a miss. Each cache read, store and invalidation is a single atomic Lua script run by its SHA, so a cache hit costs one small Redis round trip.

Reads inside a transaction always go to the database, and invalidations of writes inside a transaction run when the outermost transaction commits; a rollback discards them, a savepoint rollback keeps those of the enclosing transaction. A transaction opened behind Laravel, with a raw `START TRANSACTION` or on the PDO object, is recognised from the driver: its invalidations run when a raw `COMMIT` or `ROLLBACK` ends it, or with the next query after it ended on the PDO object, which Model Cache does not see happen.

Raw statements run through Laravel invalidate too. Every statement passing through the connection, whichever method issued it (`DB::insert()`, `DB::update()`, `DB::delete()`, `DB::statement()`, `DB::affectingStatement()`, `DB::unprepared()`, `DB::select()` with `INSERT ... RETURNING`, and driver-specific overrides such as MySQL's `insert()`), invalidates every entry of the single table it writes. Statements that cannot be attributed to one table, such as DDL, multi-table updates, stored procedure calls, writable CTEs or several statements at once, retire the whole cache. Reads, session statements (`SET`, `PRAGMA`, savepoints, locks) and temporary tables invalidate nothing. Writes that bypass Laravel's database connection, such as other applications or database consoles, are not seen.

Writes also invalidate what the database changes along with them: views reading the written table, tables the triggers of the write's event write, the base tables of an updatable view written through, and the tables foreign keys change: a delete follows `ON DELETE` rules, an update follows `ON UPDATE` rules only when it assigns a referenced column, a truncate empties every referencing table, and a write affecting no rows fires no foreign key, all followed transitively. A delete pinned to primary key values (`whereKey()`, `where('id', ...)`, `whereIn('id', ...)`) narrows its single-column `CASCADE` and `SET NULL` keys to the child rows referencing those values: cached reads of the child that pin the foreign key column to other values, such as `Pet::where('owner_id', 2)` after deleting owner 1, stay cached, while every other read of the child is refreshed. Keys are compared the way the column compares them, read from the schema with the foreign key: an integer column casts what it is given, so `'05'` is 5; a decimal column compares by value, so `'1.50'` is 1.5; a text column tells `'05'` from `'5'`; a UUID column ignores case, hyphens and braces. A value the column's type cannot be keyed for, such as `'1abc'` for an integer column (which MySQL reads as 1) or a number for a text column, invalidates the child table as a whole, as do deeper cascades, `SET DEFAULT` keys, keys of other types such as dates or binary strings, and deletes whose keys are not known. Reads stay free of schema queries: until the first write after a migration or flush loads the dependency map, reads depend on a fallback tag every row-precise cascade replaces, so reads cached before that write miss once when the map is loaded. Foreign keys, view dependencies, triggers and their events are read from the schema the first time a write needs them in each cache generation, so a migration that changes them is picked up automatically. A PostgreSQL table and the tables inheriting from it, partitions included, hold the same rows: a write to a partition refreshes reads of its parent, a write to the parent refreshes reads of its partitions, and a write to one partition leaves reads of the others cached. If the schema cannot be read at all, the failure is logged as a warning, every write retires the whole cache, and the schema is read again after a minute rather than on every write. A trigger whose body Model Cache cannot parse may write any table: it is logged as a warning when the dependency map is read, and every write firing it retires the whole cache, while writes to other tables and of other events keep it. Changes the schema does not reveal, such as tables written by stored procedures a trigger calls, are listed in `model-cache.dependent_tables`, which takes effect on the next write after a deploy changes it. Materialized views only change when refreshed, which invalidates like any other statement.

Table names are tagged in lower case, so a statement spelling a table in another case than the schema still reaches its cached reads whatever the database's case sensitivity; two tables whose names differ only in case share their tags. Reads are tagged with every table their SQL names, including tables that only appear inside raw expressions such as `whereRaw('exists (select 1 from pets ...)')`.

Queries whose result depends on when or by whom they run are never cached: SQL calling `NOW()`, `CURRENT_TIMESTAMP`, `CURRENT_DATE`, `RAND()`, `UUID()`, `date('now')` and similar functions. Pass such values as bindings (for example `now()` from PHP) to keep the query cacheable. Reads are assumed to have no side effects: a query calling your own database functions that write or depend on state outside its tables must use `withoutCache()`.

On connections with read replicas (a `read` section in the connection config), results read from a replica are not cached for `model-cache.replica_lag` seconds (2 by default) after a write to one of their tables, because the replica may not have the write yet. Reads on the write connection, sticky reads after a write, and reads with `useWritePdo()` are cached as usual.

If an invalidation cannot reach Redis, it is kept and replayed before the cache is read or written again; until the replay succeeds, reads bypass the cache. Under PHP-FPM the pending invalidations are shared across requests and processes through APCu when available. Very large backlogs retire the whole cache instead.

The cache key and the cached rows do not depend on the connection's session settings. If parts of your application change session settings that alter results, such as the MySQL or PostgreSQL session time zone for `TIMESTAMP` columns, use a separate connection or `withoutCache()` for those queries. Applications that share one Redis instance must each use their own `model-cache.prefix`.

Model Cache never breaks database access. If Redis is unreachable or a cache operation fails, the failure is logged and the query runs against the database as if Model Cache were not installed. After a failure, Redis is skipped for 30 seconds, so an outage costs at most one connection attempt per worker in that window: under FrankenPHP worker mode or Octane the skip lives as long as the worker, and under PHP-FPM it is shared across requests and processes through APCu when the APCu extension is enabled (otherwise every request tries once). Set short `timeout` and `read_timeout` values on the Redis connection Model Cache uses to bound that attempt. Failed invalidations are logged as errors because other hosts may serve the previous results until they expire (`MODEL_CACHE_EXPIRATION`, 7 days by default).

Each expiration is spread randomly by up to `MODEL_CACHE_EXPIRATION_JITTER` percent (15 by default) in either direction, so entries cached together, for example right after a deploy, do not all expire and hit the database at the same moment. Set it to 0 for exact expiration times.

## Console Commands

```bash
# Flush all cached entries: retiring them is a single Redis command, after which the
# leftover keys are deleted in non-blocking SCAN batches (about 1 second per million keys)
php artisan model-cache:flush

# Invalidate the cached reads of specific tables, after changes made outside Laravel
php artisan model-cache:invalidate users orders [--connection=mysql]

# Report what the metrics counted (see Metrics)
php artisan model-cache:stats [--hours=24] [--table=users] [--json]

# Temporarily disable cache
php artisan model-cache:disable

# Re-enable cache
php artisan model-cache:enable
```

## Metrics

Set `MODEL_CACHE_METRICS=true` to count what the cache does in production. `php artisan model-cache:stats` then reports, per table, hits, misses, the hit rate, stores, stores per hit (above 1 means entries are invalidated before they are read, so caching that table costs more than it saves), results too large to store, the invalidations the table's writes caused and how many tables their cascades reached on average. In total it reports retires by reason, reads that bypassed the cache by reason, failures by operation, reads that ran uncached because their SQL could not be analysed and statements that retired the whole cache, both grouped by statement shape. `--json` prints the same for scraping into a monitoring system.

Counting is built to cost as little as possible:

- With metrics off, nothing changes: the Redis scripts take the arguments they always took and count nothing.
- Hits, misses, stores, invalidations and retires are counted by the Redis script that performs them, as one `HINCRBY` inside the call Model Cache makes anyway. No read or write takes an extra round trip.
- What only PHP sees (bypasses, failures, statement shapes) is counted in memory and written in one call when a request or job ends, and at most every 10 seconds by a long-lived worker such as Octane or FrankenPHP. A worker that stops loses the counts of its last seconds.

Counters are kept in one Redis hash per time bucket (`MODEL_CACHE_METRICS_BUCKET`, 3600 seconds by default) that expires after `MODEL_CACHE_METRICS_RETENTION` (8 days by default). They live outside the cache generation, so flushes and migrations keep them. A read or write is counted under its first table.

## Known Issues and Limitations

- Multiple connections (`DB::connection('foo')`) are only supported when using the package’s connection integration. Models defining `$connection` work automatically.  
- Third-party packages with custom query builders may bypass caching.
- Complex SQL constructs such as `UNION`/`INTERSECT`/advanced expressions may not be fully reflected for row-level tagging; invalidation falls back to table-level tags.
- Raw SQL executed directly via the connection (e.g., `DB::select()`, `DB::statement()`) is not cached by design.
- Row-level tagging relies on standard single-column primary keys. Composite or unconventional primary keys fall back to table-level invalidation.
- Redis Cluster is not supported, because each cache operation is one Lua script touching keys of several tags. Use a standalone Redis, optionally with replicas and Sentinel.
- A restarted Redis or a promoted replica may have lost the newest invalidations. Model Cache records the Redis server's run id and retires the cache the first time a store or invalidation reaches a different server, and the first time a lookup does that checks: every process checks on its first lookup and every 5 seconds after, since reading the run id costs more than the rest of a hit. Cached reads served within those seconds, before any store or write anywhere, may still be stale.
- Give Redis an eviction policy such as `allkeys-lru` or `volatile-lru`. Evicted entries and version tokens only cause misses, but under `noeviction` a full Redis rejects invalidations, and `volatile-ttl` evicts the short-lived replica lag markers first.
- Writes that bypass Laravel's connection (other applications, database consoles, `DB::connection()->getPdo()`) are not seen; run `php artisan model-cache:invalidate <tables>` (or `model-cache:flush`) after such changes.

## Contributing

Pull requests and issue reports are welcome!

- Format with **Pint** (`just format`) and keep `just lint analyse` clean  
- Add tests for all new features  
- Submit via feature branches (no direct PRs from `master`)

### Development environment

The PHP version, its extensions (`redis`, `apcu`, `pcntl`, `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`) and Composer come from the Nix flake, so nothing needs installing by hand:

```bash
nix develop        # or direnv allow, the repository has an .envrc
just               # list the recipes
```

The databases run as containers behind `just services`; they are published on ports 13306 (MySQL), 15432 (PostgreSQL) and 16379 (Redis), so they never collide with a database already running on the machine. `just env` prints the exports needed to run PHPUnit by hand.

### Running the tests

The suites run against SQLite, MySQL 8.4 and PostgreSQL 17, with both Redis clients:

```bash
just test                 # SQLite with phpredis
just test-mysql           # MySQL
just test-pgsql           # PostgreSQL
just test-matrix          # every database with phpredis and Predis
just test --filter=RowKeysTest
MODEL_CACHE_FUZZ_ITERATIONS=5000 just fuzz
just concurrency          # several processes against MySQL and PostgreSQL
just lint analyse         # Pint and PHPStan
```

- **Unit** and **Integration** cover behaviour case by case.
- **Fuzz** runs a seeded random workload of reads, writes, raw statements, transactions, foreign key cascades, cache flushes, an emptied Redis and evicted keys, and compares every cached read with the same query run without the cache. A failure prints the seed and the operations that led to it; rerun with `MODEL_CACHE_FUZZ_SEED=<seed>`.
- **Benchmark** (`just bench`) reports reads per second with and without the cache, and the Redis commands a cached read costs.
- **Concurrency** forks worker processes, like pods sharing one database and Redis, runs the same workload in all of them at once, and checks that every cached read matches the database once they finish.

## License

Model Cache is open-source software licensed under the **MIT License**.
