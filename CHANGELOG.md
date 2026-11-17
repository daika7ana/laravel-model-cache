# Changelog

All notable changes to `laravel-model-cache` will be documented in this file.

## [1.1.1] - 2026-11-17

### Changed
- `withCount()` / `withSum()` / `withAvg()` / `withMin()` / `withMax()` / `withExists()` now cap the root query's TTL the same way `with()` does: the shortest `$cacheMinutes` across the root, its eager loads, and its aggregate relations wins, and `0` on a related model disables the root snapshot. Previously only eager loads affected the root TTL.
- Stores without tag support warn once per model class instead of on every write; the flush is still skipped and `flushModelCache()` still returns `false`.

### Fixed
- `morphToMany()` / `morphedByMany()` pivot writes did not invalidate anything: only the `belongsToMany` factory was wrapped, so `attach()`/`detach()`/`sync()` on a polymorphic pivot left every cached eager load stale until the TTL expired. `HasCachedRelationships` now wraps both, sharing the batching and transaction-deferral logic with `CachingBelongsToMany` through one trait.
- `withoutCache()->getFromCache()` and `remember(0)->getFromCache()` returned cached data. A `0` TTL now bypasses the cache on every explicit `*FromCache()` read, matching `firstFromCache()`.
- Cache flush paths caught `\Exception` only, so an `\Error` raised by the cache backend escaped to the caller. They now catch `\Throwable` and fail open (`flushModelCache()` / `flushCache()` return `false`).
- Reads, flushes and `mcache:flush` each resolved `model-cache.cache_store` on their own, so an unusable store could make a flush target a store the reads never wrote to. One resolver now serves all of them, logging a warning and falling back to `cache.default`.
- `mcache:flush <Model>` reported every flush that returned `false` as "the configured store does not support tags", even when the tag flush had just thrown. A failed flush and a tag-less store now print different messages; the exit code stays `1`.
- The exception raised when a `CachingBelongsToMany` parent has no flush method named the nonexistent `ModelRelationships` trait; it now names `HasCachedRelationships`.

## [1.1.0] - 2026-10-30

### Added
- `morphTo` eager loads are keyed and tagged per type registered in `Relation::morphMap()`; results containing an unmapped type are returned but not cached. Without a morph map, `morphTo` eager loads bypass the cache.
- Cache tags for tables read by eager-load constraints, joins, `whereHas`/`whereIn`/`selectSub()` sources, unions, `withCount()`/`withSum()`/… aggregates, and pivot/through tables.
- Cache keys include the resolved state of every eager load: relation SQL/bindings, global scopes, `$with`, pivot settings, `as()`/`using()`, and nested relations. Existing entries will miss after upgrading.

### Changed
- Per-model flushes now reset only that model's class/table tags instead of the shared `model_cache` tag, so writing one model no longer evicts unrelated models. Destructive flushes (`delete`, `forceDelete`, `truncate`) still reset the shared tag, because database cascades delete other models' rows without firing their events. `mcache:flush` with no argument still clears all cached model queries; `mcache:flush <Model>` uses the selective tags.
- Pivot writes flush the related model's tags and the pivot table's tag, so cached relations and eager loads on either side see the change. `sync()` batches its per-id writes into a single flush when rows changed.
- The shortest `$cacheMinutes` now also caps the root query when a relation is included via `withCount()`/`withSum()`/`withAvg()`/`withMin()`/`withMax()`/`withExists()`, and `0` disables the root snapshot.
- Eager-load constraint closures run whenever a key or tag set is computed, including cache hits. Keep them side-effect free; avoid database queries inside them.
- Aggregate queries remain cacheable when the builder has an `afterQuery()` callback; the callback only affects model results.
- Key generation serializes scalar binding lists as one array instead of base64-encoding each value, so large `whereIn()` keys are exact, binary-safe and about 5× faster to build (10k UUIDs: ~1.3 ms → ~0.24 ms).

### Fixed
- Stale cached snapshots when a table was read inside an eager-load constraint (join or `whereHas`) but was not tagged.
- Query bindings and columns are fingerprinted losslessly with PHP's `serialize()`: binary strings, binding types and objects are preserved exactly, and `Expression` objects use their raw SQL. Scalar binding lists are serialized as one array. Values that cannot be fingerprinted (resources, closures, opaque subquery sources) make the query bypass the cache instead of risking a key collision.
- Queries with `afterQuery()` callbacks (on the base query or an eager-loaded relation, including `chaperone()`) bypass the cache, because the callback mutates the result after the query runs.
- Raw SQL subqueries in `selectRaw`/`addSelect`, `whereRaw`/`havingRaw`/`groupByRaw`, `orderByRaw`, join `ON` clauses, nested `where` blocks, `whereIn($column, $subquery)` and the columns passed to `get()`/`paginate()` are now detected and bypass the cache instead of being cached without dependency tags.
- Cascaded rows of other models stay cached after a `delete`/`forceDelete`/`truncate` because the cascade fires no model events. Destructive flushes now also reset the shared `model_cache` tag.
- A `sync()` that fails after committing some pivot rows left the relation cache stale; the failure path now flushes before rethrowing.
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
