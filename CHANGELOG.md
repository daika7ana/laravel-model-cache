# Changelog

All notable changes to `laravel-model-cache` will be documented in this file.

## [Unreleased]

### Added
- `morphTo` eager loads are keyed and tagged per type registered in `Relation::morphMap()`; results containing an unmapped type are returned but not cached. Without a morph map, `morphTo` eager loads bypass the cache.
- Cache tags for tables read by eager-load constraints, joins, `whereHas`/`whereIn`/`selectSub()` sources, unions, `withCount()`/`withSum()`/… aggregates, and pivot/through tables.
- Cache keys include the resolved state of every eager load: relation SQL/bindings, global scopes, `$with`, pivot settings, `as()`/`using()`, and nested relations. Existing entries will miss after upgrading.

### Changed
- Per-model flushes now reset only that model's class/table tags instead of the shared `model_cache` tag, so writing one model no longer evicts unrelated models. `mcache:flush` with no argument still clears all cached model queries; `mcache:flush <Model>` uses the selective tags.
- Pivot writes flush the related model's tags and the pivot table's tag, so cached relations and eager loads on either side see the change. `sync()` batches its per-id writes into a single flush when rows changed.
- The shortest `$cacheMinutes` now also caps the root query when a relation is included via `withCount()`/`withSum()`/`withAvg()`/`withMin()`/`withMax()`/`withExists()`, and `0` disables the root snapshot.
- Eager-load constraint closures run whenever a key or tag set is computed, including cache hits. Keep them side-effect free; avoid database queries inside them.
- Aggregate queries remain cacheable when the builder has an `afterQuery()` callback; the callback only affects model results.
- Key generation serializes scalar binding lists as one array instead of base64-encoding each value, so large `whereIn()` keys are exact, binary-safe and about 5× faster to build (10k UUIDs: ~1.3 ms → ~0.24 ms).

### Fixed
- Stale cached snapshots when a table was read inside an eager-load constraint (join or `whereHas`) but was not tagged.
- Query bindings and columns are fingerprinted losslessly with PHP's `serialize()`: binary strings, binding types and objects are preserved exactly, and `Expression` objects use their raw SQL. Scalar binding lists are serialized as one array. Values that cannot be fingerprinted (resources, closures, opaque subquery sources) make the query bypass the cache instead of risking a key collision.
- Queries with `afterQuery()` callbacks (on the base query or an eager-loaded relation, including `chaperone()`) bypass the cache, because the callback mutates the result after the query runs.
- Raw SQL subqueries in `selectRaw`/`addSelect`, `whereRaw`, `orderByRaw`, or passed as `$columns` to `get()`/`paginate()` are now detected and bypass the cache instead of being cached without dependency tags.
- Opaque subquery sources (`fromSub()`, `joinSub()`, `selectSub()` closures/raw SQL) bypass the cache instead of being cached without dependency tags.

## [1.0.1] - 2026-10-13

### Fixed
- Infinite recursion and unbounded memory use when a cache flush ran inside a transaction. The `afterCommit()` deferrals in `flushModelCache()` and `flushQueryCache()` re-registered themselves as the callback; under Laravel's testing transactions manager (which runs callbacks immediately while the wrapping transaction is still open) this recursed forever. Deferred callbacks now perform the flush directly.

## [1.0.0] - 2026-10-13

### Added
- Transparent Eloquent query caching via the `HasCachedQueries` trait and cache-aware builder (`get`, `first`, `paginate`, `count`, `sum`, `max`, `min`, `avg`, plus explicit `*FromCache` methods).
- Tag-based invalidation, flushed automatically on create, update, delete, restore, mass operations, and `belongsToMany` attach/detach/sync/updateExistingPivot.
- Optional stampede prevention (`use_cache_locks`) with a bounded polling loop.
- Fail-open error handling: any cache failure falls through to the database.
- Transaction-aware invalidation: flushes are deferred until commit and run exactly once per operation.
- `mcache:flush` Artisan command for manual cache clearing.
- Per-model `$cacheMinutes`, `$cachePrefix`, and `$cacheLockSeconds`; chainable `remember()`, `withoutCache()`, `flushCache()`, and `paginateFromCache()`.
- Debug logging for key generation, cache hits/misses, and flush operations (`debug_mode`).

### Changed
- Cache keys are derived from the model class, connection, database, table, SQL, bindings, columns, and fingerprinted eager-load constraints; hashing is configurable (`hash_algorithm`).
- Full-cache-flush confirmations default to "no".

### Compatibility
- PHP 8.2+ and Laravel 11, 12, and 13.
