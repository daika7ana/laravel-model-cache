# How to Troubleshoot Common Issues

## Queries always hit the database

1. Is `HasCachedQueries` on the model?
2. Is `model-cache.enabled` true?
3. Is the query using `withoutCache()` or `remember(0)`, or is the model's `$cacheMinutes` set to `0`?
4. Is the store tag-capable (Redis, Memcached)? Tag-less stores (file, database) bypass caching entirely.
5. Is a database transaction open on the query's connection? Reads inside a transaction bypass the cache.
6. Does the query use `lockForUpdate()` / `sharedLock()`? Locked reads bypass the cache so the lock is actually taken.
7. Does the query use `inRandomOrder()` (directly or inside an eager load)? Random-ordered reads bypass the cache so each call gets a fresh order.

## Stale data

- Writes went through a path that bypasses the package: raw `DB` writes, or query-builder writes on a model without the trait.
- The store doesn't support tags (file, database): reads bypass the cache entirely (nothing is cached that could not be invalidated), flushes are **skipped** instead of wiping unrelated application cache, and the first write logs a warning (once per model class). Use Redis or Memcached to actually cache reads.
- Pivot changes on a model without `HasCachedRelationships`.
- `withCount()` / `withSum()` / `withAvg()` / `withExists()` are compiled into the parent query's SQL (not eager loads). The package records the relation tables when the aggregate is added, so a write to the related model invalidates the parent query through those dependency tags.
- A query using an opaque subquery source (`fromSub()`, `joinSub()`, `selectSub()` with a closure/raw SQL) deliberately bypasses the cache because its dependencies cannot be tagged safely. `morphTo` eager loads are supported when every morph type is registered in `Relation::morphMap()`: they are keyed and tagged per mapped target, including each target's global scopes. Without a morph map, `morphTo` eager-load queries bypass the cache. A result containing an unmapped morph type is returned but not cached.
- A query with an `afterQuery()` callback bypasses the cache because the callback mutates the result after the query runs.

## Stale data right after a write

On version-based tag stores (Memcached, array), a read that misses the cache snapshots the tag namespace before it runs the query. If a writer flushes while the query runs, the package detects the changed namespace and discards the pre-write result instead of caching it. A tiny window remains: a flush that lands after that check but before the write still lets the stale result through. On Redis, flushes delete the tagged keys directly rather than bumping a namespace, so there is no namespace to snapshot; the guard is a no-op there and the concurrent write-after-flush window remains (a normal, non-concurrent flush still invalidates correctly).

The sequence:

1. A request misses the cache, snapshots the tag namespace, and starts a slow query (`S1`).
2. A writer commits and flushes the model's tags.
3. If the flush finished before `S1`'s namespace re-check, `S1` is discarded; otherwise `S1` is written under the new namespace.
4. In that rare second case, reads get `S1` until the entry expires or another write flushes it.

The stampede lock (`MODEL_CACHE_USE_LOCKS`) doesn't help here — it only serializes readers against each other, not against writers.

Mitigation: give models that are written while they are being read a short TTL (`$cacheMinutes` of 1–5) so the residual window is bounded.

## Cache driver outages

The package fails open: cache read errors fall through to the database and queries keep working. With `MODEL_CACHE_DEBUG=true`, watch your logs for `Cache read failed` messages.

## Stale data under high traffic

Enable stampede prevention (`MODEL_CACHE_USE_LOCKS=true`) so concurrent requests collapse onto the lock holder's result. Waiters re-read for at most `MODEL_CACHE_LOCK_WAIT_SECONDS` (default 0.25s); raise it above your slowest query if cold keys take longer, otherwise waiters fall back to running the query themselves.

## Debugging flow

1. Run `php artisan mcache:flush`.
2. Re-run the query and verify freshness.
3. Temporarily set `MODEL_CACHE_DEBUG=true` and inspect `debug.log` for key generation and flush activity.
