# How to Troubleshoot Common Issues

## Queries always hit the database

1. Is `HasCachedQueries` on the model?
2. Is `model-cache.enabled` true?
3. Is the query using `withoutCache()`?

## Stale data

- Writes went through a path that bypasses the package: raw `DB` writes, or query-builder writes on a model without the trait.
- The store doesn't support tags (file, database): writes log a warning and **skip the flush** instead of wiping unrelated application cache. Use Redis or Memcached for selective invalidation. (Reads still cache with any driver.)
- Pivot changes on a model without `HasCachedRelationships`.

## Cache driver outages

The package fails open: cache read errors fall through to the database and queries keep working. Watch your logs for `Cache read failed` messages.

## Stale data under high traffic

Enable stampede prevention (`MODEL_CACHE_USE_LOCKS=true`) so a cold cache key is recomputed once instead of by every concurrent request.

## Debugging flow

1. Run `php artisan mcache:flush`.
2. Re-run the query and verify freshness.
3. Temporarily set `MODEL_CACHE_DEBUG=true` and inspect `debug.log` for key generation and flush activity.
