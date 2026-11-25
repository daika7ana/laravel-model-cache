# AGENTS.md

Laravel package (fork of `ymigval/laravel-model-cache`) that caches Eloquent queries via a cache-aware builder with tag-based invalidation. PHP `^8.3`, Laravel 11–13, Orchestra Testbench for tests. Namespace: `Daika7ana\LaravelModelCache`.

## Commands

- `composer test` — `vendor/bin/phpunit` (Unit + Feature suites; 263 tests, 675 assertions, ~5s; redis 673 with 1 intentional skip). phpunit.xml defaults to the `array` store. For real tag-store runs: `docker compose up -d` (memcached:1.6 on 11211, redis:8-alpine on 6379), then `CACHE_DRIVER=memcached vendor/bin/phpunit` and `CACHE_DRIVER=redis vendor/bin/phpunit` (needs the matching PHP ext). All three drivers pass.
- `composer check-style` — `vendor/bin/pint --test`. Passes on HEAD. `composer fix-style` — `vendor/bin/pint --dirty` (only touched files); plain `vendor/bin/pint` sweeps the repo.
- Pint preset is `per`, **not** `laravel` — it enforces `ordered_class_elements`, `function_declaration`, etc. Run `vendor/bin/pint --dirty` after adding test helper methods; a fresh private method mid-class often trips `ordered_class_elements`.

## Testing quirks

- `tests/TestCase.php` (Testbench): sqlite `:memory:` DB (`database.default` = 'testing'), migrations auto-loaded from `tests/Fixtures/database/migrations`, package config overridden with `test_cache_` key prefix; `cache.default` = env `CACHE_DRIVER` (default 'array').
- `tests/TestCase.php` defines cache stores `array`, `database`, `memcached` (`MEMCACHED_HOST`/`MEMCACHED_PORT`, default 127.0.0.1:11211) and `redis` (`REDIS_HOST`/`REDIS_PORT`, default 127.0.0.1:6379, phpredis client). CI is `.github/workflows/ci.yml`: matrix PHP 8.3/8.4/8.5 × driver memcached/redis/array (caches via GH services + pecl exts; `CACHE_DRIVER` step env overrides phpunit.xml because PHPUnit only sets env vars that aren't already defined). `composer.lock` and `debug.log` are gitignored, so CI runs `composer update`.
- `MODEL_CACHE_DEBUG=true` env → debug logs written to `debug.log` in repo root (gitignored).
- **Asserting log output:** Laravel 13 has NO `Log::fake()`/`Log::assertLogged`. Use `tests/Fixtures/RecorderLogger.php` (PSR `AbstractLogger`) + `$this->app->instance('log', $logger)`, then inspect `$logger->records` (`[$level, $message]` tuples). Used for the debug-gating tests (RegressionTest) and the non-tag-store warning test.
- **Counting flushes:** `CacheInvalidationTest::installDebuggerSpy()` swaps the `ModelCacheDebugger` singleton for a spy counting `info()` messages containing 'statically' (= `flushModelCache` executions). Bind it BEFORE the first model use — `bootHasCachedQueries()` captures the debugger at boot.
- **Non-tag store path:** switch `config('cache.default', 'database')` mid-test and call `$this->ensureDatabaseCacheTables()` (protected on `TestCase`) to create the cache/cache_locks tables.
- **Stampede tests** set `cache_lock_seconds` low (1s) because `rememberWithLock` polls every 50ms up to the lock duration.
- **Mocking console confirms:** `$this->artisan()` CANNOT observe `confirm()` defaults — its mocked `askQuestion` returns the registered answer directly. Mock `OutputStyle::askQuestion` (partial Mockery mock with `[new ArrayInput([]), new BufferedOutput()]`) and inspect `ConfirmationQuestion::getDefault()`; run the command via `$command->run(new ArrayInput([...]), $output)` after `$command->setLaravel($this->app)`, and DON'T include a `command` key in the ArrayInput (not in the command's definition; binding it throws).

## Core semantics (don't break these)

- `HasCachedQueries` overrides `newEloquentBuilder()` → `CacheableBuilder` (extends Eloquent `Builder`, `src/CacheableBuilder.php`, ~2500 lines). `get()`/`first()`/`paginate()`/`count()`/`sum()`/`max()`/`min()`/`avg()` on a trait model become cache reads; mass ops (`update`, `delete`, `insert`, `upsert`, `truncate`, `increment`, …) flush via the single choke point `flushModelOrQueryCache()`.
- Invalidation is tag-based: tags `['model_cache', ModelClass, tableName]`. With a store that doesn't support tags, `flushModelCache()` **skips the flush, logs a warning, and returns false** — it never wipes the whole cache (that would nuke sessions/auth). Tag-capable stores (Redis/Memcached; array in tests) are required both for caching reads and for invalidation — on a tag-less store reads bypass the cache entirely; `model-cache.cache_store` (`MODEL_CACHE_STORE` env) can point elsewhere.
- Transaction deferral: EVERY flush path (builder mass ops, model events, `CachingBelongsToMany`, relationship helper methods) defers through `HasCachedQueries::deferFlushUntilCommit($connection, $flush)` — `afterCommit()` on the writing connection while its `transactionLevel() > 0`, immediate otherwise — never flush on rollback. The deferred flush runs on the connection that performed the write: internal callers pass it as `flushModelCache($operation, $connection)`; bare static calls fall back to the model's default connection.
- Single flush per instance op: the builder flushes then calls `markCacheFlushed($model)`; the event handler checks `consumeCacheFlushMarker()` (deleted/restored) / `hasCacheFlushMarker()` (created/updated, peek-not-clear — restore fires updated then restored) to skip its redundant flush. Identity-based (`===`), so stale markers can't suppress a legit flush.
- Fail-open: any cache exception falls through to the DB; `model-cache.enabled=false` bypasses caching entirely.
- Cache key = hash of `prefix|modelClass|connection name|database|host|url|port|table|sql|bindings|columns|locale?` plus eager-load names; bindings/columns are fingerprinted with PHP `serialize()` (lossless, binary-safe; `Expression` → raw SQL) — keep it, `json_encode` is not binary-safe. Algorithm from `model-cache.hash_algorithm` (default `xxh128`), validated at boot in `ModelCacheServiceProvider`; runtime `hash()` failures surface as `InvalidArgumentException`, not `ValueError`.
- Stampede prevention (`model-cache.use_cache_locks`): `rememberWithLock` re-reads the cache every 50ms for at most `min($cacheLockSeconds ?? cache_lock_seconds, lock_wait_seconds [default 0.25])` seconds, then falls back to executing the query instead of pinning the worker for the whole lock duration. `MODEL_CACHE_LOCK_WAIT_SECONDS` tunes the cap. Lock key is `stampede:{cacheKey}`; a null re-read must never be returned to `Collection`-typed methods.
- Per-model props: `$cacheMinutes`, `$cachePrefix`, `$cacheLockSeconds`. Chain API: `remember()`, `withoutCache()`, `flushCache()`, `paginateFromCache(..., $total)` (`$total` enters the cache key only when non-null), explicit `getFromCache()`/`firstFromCache()`. `touch()` returns `int|false`.
- `HasCachedRelationships` (or `HasCacheableModel` = both traits) overrides `belongsToMany()` → `CachingBelongsToMany`, which flushes after attach/detach/sync/syncWithoutDetaching/updateExistingPivot (gated on actual pivot changes). Both contracts were removed, and the `scopeFlushCache` scope that consumed one of them is gone too (it was shadowed by the builder's own `flushCache()`).
- Console: `php artisan mcache:flush [ModelClass]` exits `1` for a non-existent or non-Eloquent class; all full-cache-flush confirms default to `false`.

## Contributing workflow (this fork)

- Base branch is `master` (the only remote branch). Changes go on a `fix/...` or `refactor/...` branch with ONE commit per logical change, squash-merged via `gh pr merge N --squash --delete-branch`, then `git checkout master && git pull --ff-only && git fetch --prune`.
- The user tests manually between changes; push/PR is requested explicitly. `FINDINGS.md` is a scratch file — never commit it, never mention it in PRs. `AGENTS.md` is tracked. Commit style: `fix: ...` / `feat: ...` / `refactor: ...` / `test: ...`.
- Laravel 11/12 compatibility is source-verified only (local vendor is pinned to 13.23.0): keep overrides signature-compatible with 11.x/12.x — don't type-hint parameters vendor widened later (e.g., `touch($column)` is `array|string|null` in 12.x), and keep `belongsToMany()` at exactly 7 params (no `$inverse` in 11/12/13).

## Layout

- `src/` — package code; config in `config/model-cache.php` (published as `model-cache`).
- `tests/` — `Unit/`, `Feature/`, shared fixtures in `tests/Fixtures/` (models, migrations, `RecorderLogger`, `ThrowingFlushModel`).
- `docs/` — HOW-TO guides linked from `README.md`; update them when user-facing behavior changes (per `CONTRIBUTING.md`).
