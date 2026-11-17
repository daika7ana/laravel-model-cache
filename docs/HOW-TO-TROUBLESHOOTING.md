# How to Troubleshoot Common Issues

## Queries always hit the database

1. Is `HasCachedQueries` on the model?
2. Is `model-cache.enabled` true?
3. Is the query using `withoutCache()`?

## Stale data

- Writes went through a path that bypasses the package: raw `DB` writes, or query-builder writes on a model without the trait.
- The store doesn't support tags (file, database): flushes are **skipped** instead of wiping unrelated application cache, and the first write logs a warning (once per model class). Use Redis or Memcached for selective invalidation. (Reads still cache with any driver.)
- Pivot changes on a model without `HasCachedRelationships`.
- `withCount()` / `withSum()` / `withAvg()` / `withExists()` are compiled into the parent query's SQL (not eager loads). The package records the relation tables when the aggregate is added, so a write to the related model invalidates the parent query through those dependency tags.
- A query using an opaque subquery source (`fromSub()`, `joinSub()`, `selectSub()` with a closure/raw SQL) deliberately bypasses the cache because its dependencies cannot be tagged safely. `morphTo` eager loads are supported when every morph type is registered in `Relation::morphMap()`: they are keyed and tagged per mapped target, including each target's global scopes. Without a morph map, `morphTo` eager-load queries bypass the cache. A result containing an unmapped morph type is returned but not cached.
- A query with an `afterQuery()` callback bypasses the cache because the callback mutates the result after the query runs.

## Stale data right after a write

A read that starts before a write can finish after the write's flush and store its pre-write result under the refreshed tag namespace. Tags don't prevent this: the writer resets the namespace, but the pending read resolves it only when it stores its result, which is after the reset.

The sequence:

1. A request misses the cache and starts a slow query (`S1`).
2. A writer commits and flushes the model's tags.
3. `S1` finishes and is written under the new namespace.
4. Every read gets `S1` until the entry expires or another write flushes it.

The stampede lock (`MODEL_CACHE_USE_LOCKS`) doesn't help here — it only serializes readers against each other, not against writers.

Mitigation: give models that are written while they are being read a short TTL (`$cacheMinutes` of 1–5) so the window is bounded. Invalidation alone cannot close it.

## Cache driver outages

The package fails open: cache read errors fall through to the database and queries keep working. With `MODEL_CACHE_DEBUG=true`, watch your logs for `Cache read failed` messages.

## Stale data under high traffic

Enable stampede prevention (`MODEL_CACHE_USE_LOCKS=true`) so a cold cache key is recomputed once instead of by every concurrent request.

## Debugging flow

1. Run `php artisan mcache:flush`.
2. Re-run the query and verify freshness.
3. Temporarily set `MODEL_CACHE_DEBUG=true` and inspect `debug.log` for key generation and flush activity.
