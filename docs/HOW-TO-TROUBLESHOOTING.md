# How To Troubleshoot Common Issues

## Cache does not seem to be used

Check these first:

1. Model includes `HasCachedQueries`.
2. `model-cache.enabled` is `true`.
3. Cache store is configured correctly.
4. Query is not intentionally bypassing cache with `withoutCache()`.

## Cache is not invalidating

Common causes:

1. Data changed through direct query builder operations that bypass model lifecycle events.
2. Cache driver does not support tags (file, array, database). Without tags, invalidation is skipped to avoid flushing unrelated application cache. Use Redis or Memcached.
3. Relationship changes are made without `HasCachedRelationships` where needed.

## Relationship changes not reflected

For many-to-many operations, add `HasCachedRelationships` and use:

- `syncRelationshipAndFlushCache(...)`
- `attachRelationshipAndFlushCache(...)`
- `detachRelationshipAndFlushCache(...)`

## Cache driver failures

If the cache driver (Redis, Memcached) becomes unavailable, the package falls through to the database automatically. Your application continues working — errors are logged but queries are not blocked. Check your logs for `Cache read failed` messages.

## Stale data during high traffic

If you see stale data under heavy load, enable stampede prevention:

```env
MODEL_CACHE_USE_LOCKS=true
```

This prevents multiple concurrent requests from all missing the cache simultaneously and overwhelming the database.

## Safe debugging flow

1. Run: `php artisan mcache:flush`
2. Re-run the query and verify data freshness.
3. Confirm your model traits and cache driver setup.
4. Enable `debug_mode` temporarily to see cache key generation and flush operations in your logs.
