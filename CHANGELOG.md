# Changelog

All notable changes to `laravel-model-cache` will be documented in this file.

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
