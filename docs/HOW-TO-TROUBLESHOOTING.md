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
2. Cache driver does not fully support tags.
3. Relationship changes are made without `HasCachedRelationships` where needed.

## Relationship changes not reflected

For many-to-many operations, add `HasCachedRelationships` and use:

- `syncRelationshipAndFlushCache(...)`
- `attachRelationshipAndFlushCache(...)`
- `detachRelationshipAndFlushCache(...)`

## Safe debugging flow

1. Run: `php artisan mcache:flush`
2. Re-run the query and verify data freshness.
3. Confirm your model traits and cache driver setup.
