# API Reference

Complete reference for the package's public surface. For setup, see [HOW-TO-INSTALL](HOW-TO-INSTALL.md); for typical usage, see [HOW-TO-USAGE](HOW-TO-USAGE.md).

## What is cached automatically

With `HasCachedQueries` on a model, these calls read (and write) the cache:

- `get()` — and everything built on it: `first()`, `find()`, `findOrFail()`, `firstOrFail()`, `chunk()`/`cursor()` iteration, `simplePaginate()`
- `count()`, `sum()`, `max()`, `min()`, `avg()` (and `average()`)
- `paginate()` — cached as a single key (count + items together)

Eager-loaded relationships are cached together with the parent query (`with('tags')` produces a distinct cache key). Global scopes are part of the query SQL and therefore of the key.

Calls that never touch the cache: `withoutCache()` chains, `model-cache.enabled = false`, per-model `$cacheMinutes = 0`, and any cache failure (fail-open falls through to the database).

## Builder methods

All methods below are available on query chains (`Post::where(...)->method()`). Methods without a `FromCache` suffix are the Eloquent overrides that cache automatically.

| Method | Behavior |
|--------|----------|
| `get($columns = ['*'])` | Cached collection; `cacheMinutes === 0` or disabled → plain query. |
| `getFromCache($columns = ['*'])` | Explicit cached read (same as `get()` when caching is active). |
| `firstFromCache($columns = ['*'])` | Explicit cached single result (`LIMIT 1`, distinct key from `get()`); returns model or `null`. |
| `remember($minutes)` | Per-query TTL in minutes. When caching is globally disabled it degrades to `withoutCache()`. |
| `withoutCache()` | Disables caching for this chain (sets `cacheMinutes = 0`). |
| `paginateFromCache($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)` | Cached length-aware paginator. A non-null `$total` overrides the total count and changes the cache key. |
| `countFromCache($columns = '*')` | Cached count (int). |
| `sumFromCache($column)` / `maxFromCache($column)` / `minFromCache($column)` / `avgFromCache($column)` | Cached aggregates. |
| `getCacheKey($columns = ['*'])` | The cache key this chain would use (handy for debugging). |
| `flushCache($columns = ['*'])` (alias `flushQueryCache`) | Flushes this exact query's key, then the model's tags. Callable in a chain: `Post::where('published', true)->flushCache()`. |
| `touch($column = null)` | Override that flushes the cache; returns `int|false` like the vendor builder (use `$model->touch()` for the save-based variant). |

## Model methods

| Method | Behavior |
|--------|----------|
| `Post::flushModelCache()` | Static. Flushes the model's tag set; returns `false` (and logs a warning) if the store doesn't support tags. |
| `$post->flushCache()` | Instance equivalent of the above. |
| `scopeFlushCache($query)` | Local scope enabling `Post::where(...)->flushCache()` chains. |

### Per-model properties

| Property | Default | Effect |
|----------|---------|--------|
| `$cacheMinutes` | `null` → `config('model-cache.cache_duration')` | TTL in minutes. `0` disables caching for this model. |
| `$cachePrefix` | `null` → `config('model-cache.cache_key_prefix')` | Cache key prefix. |
| `$cacheLockSeconds` | `null` → `config('model-cache.cache_lock_seconds')` | Stampede lock duration (seconds). |

## Traits

| Trait | Contents |
|-------|----------|
| `HasCachedQueries` | The caching builder + invalidation events. Required for any caching. |
| `HasCachedRelationships` | `belongsToMany()` returns `CachingBelongsToMany`, which auto-flushes on pivot changes. Also provides legacy helper methods (below). |
| `HasCacheableModel` | Convenience: both of the above. |

### Pivot operations that auto-flush

On a model with `HasCachedRelationships`, these flush the model cache — gated on actual changes:

- `attach()` — skips entirely (no flush) for an empty id list
- `detach()` — flushes only when rows were actually detached
- `sync()` / `syncWithoutDetaching()` — flushes only when pivot rows changed
- `updateExistingPivot()` — flushes only when a row was updated

### Legacy helper methods (optional)

Equivalent one-liners on the model that call the relation and flush explicitly:

| Method | Notes |
|--------|-------|
| `syncRelationshipAndFlushCache($relation, array $ids, $detaching = true)` | Returns the sync result array; flushes only when changes occurred. |
| `attachRelationshipAndFlushCache($relation, $ids, array $attributes = [], $touch = true)` | Empty `$ids` → no-op, no flush. |
| `detachRelationshipAndFlushCache($relation, $ids = null, $touch = true)` | Flushes only when rows were detached. |

## Cache keys

A key is `hash(prefix | table | sql | bindings | columns | locale? | with:{eager-load names} | paginate components)`, using `config('model-cache.hash_algorithm')` (default `xxh128`; invalid algorithms are rejected at boot).

A different value in any component — e.g. a different column list, eager load, locale (when `include_locale_in_key` is on), page/per-page, or a non-null `$total` — yields a different key, so related queries are cached separately and safely.

## Invalidation

- **Model events** — `created`, `updated`, `deleted`, `restored` flush the model's cache.
- **Mass operations** — `update`, `delete`, `insert`, `insertGetId`, `insertOrIgnore`, `updateOrInsert`, `upsert`, `truncate`, `increment`, `decrement`, `forceDelete`, `restore`, `touch` flush via the builder.
- **Single flush per operation** — instance operations (e.g. `$post->delete()`) flush once even though both the builder and the model event fire (identity-based markers).
- **Transactions** — every flush is deferred with `DB::afterCommit()` inside a transaction; a rollback never flushes.
- **Non-tag stores** — `flushModelCache()` skips the flush, logs a warning, and returns `false` rather than wiping unrelated application cache. Use Redis/Memcached for selective invalidation.
- **Manual** — `Post::flushModelCache()`, `$post->flushCache()`, or `php artisan mcache:flush`.

## Stampede prevention

When `model-cache.use_cache_locks` (env `MODEL_CACHE_USE_LOCKS`) is enabled, a cache miss acquires a lock on `stampede:{cacheKey}`. Concurrent requests re-read the cache every 50 ms until the lock holder publishes the result; if the lock duration (`cache_lock_seconds`, default 5, or `$cacheLockSeconds` per model) elapses, they fall back to executing the query themselves.

## Console command

`php artisan mcache:flush [ModelClass]`

- No argument — flushes the `model_cache` tag for all models (or asks to flush the entire cache on non-tag stores; confirmation defaults to **No**).
- With a model class — validates it (`class_exists`, `is_a(..., Model::class)`); invalid input prints an error and exits with code **1**, success exits **0**.
