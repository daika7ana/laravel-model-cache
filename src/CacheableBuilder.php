<?php

namespace YMigVal\LaravelModelCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use YMigVal\LaravelModelCache\Exceptions\UnfingerprintableEagerLoadException;

class CacheableBuilder extends Builder implements \YMigVal\LaravelModelCache\Contracts\CacheableBuilderContract
{
    /**
     * The number of minutes to cache the query.
     *
     * @var int
     */
    protected $cacheMinutes;

    /**
     * Define a custom cache prefix for this model.
     * If not set, the global prefix from config('model-cache.cache_key_prefix') will be used.
     *
     * @var string
     */
    protected $cachePrefix;

    /**
     * Cached cache driver instance.
     *
     * @var \Illuminate\Contracts\Cache\Repository|null
     */
    protected $cacheDriver;

    /**
     * Per-model cache lock duration in seconds for stampede prevention.
     * Overrides config('model-cache.cache_lock_seconds') when set.
     *
     * @var int|null
     */
    protected $cacheLockSeconds;

    /**
     * Memoized eager-load constraint fingerprints, keyed by closure instance.
     * A WeakMap auto-evicts entries when a closure is freed (e.g. after
     * without()/withOnly()), so a reused object id can never return a stale
     * fingerprint.
     *
     * @var \WeakMap|null
     */
    protected $closureFingerprints;

    /**
     * Set when an eager-load constraint captures state that cannot be
     * fingerprinted (e.g. an object holding a closure). Such a query must not
     * use the cache at all — any key we build would be unsafe.
     *
     * @var bool
     */
    protected $eagerLoadUnfingerprintable = false;

    public function __construct($builder, $cacheMinutes = null, $cachePrefix = null, $cacheLockSeconds = null)
    {
        $this->cacheMinutes = $cacheMinutes;
        $this->cachePrefix = $cachePrefix;
        $this->cacheLockSeconds = $cacheLockSeconds;
        parent::__construct($builder);
    }

    /**
     * Touch all of the related models for the relationship.
     *
     * @param  null  $column
     * @return int|false
     */
    public function touch($column = null)
    {
        return $this->flushAfter(parent::touch($column), 'touch');
    }

    /**
     * Execute the query and get the first result from the cache.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model|\stdClass|static|null
     */
    public function firstFromCache($columns = ['*'])
    {
        // Check if caching is globally disabled
        if (config('model-cache.enabled', true) === false) {
            return $this->clone()->take(1)->getWithoutCache($columns)->first();
        }

        // Check if caching is disabled for this query
        if (isset($this->cacheMinutes) && $this->cacheMinutes === 0) {
            return $this->clone()->take(1)->getWithoutCache($columns)->first();
        }

        $results = $this->clone()->take(1)->getFromCache($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the results from the cache.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFromCache($columns = ['*']): Collection
    {
        if (config('model-cache.enabled', true) === false) {
            return $this->getWithoutCache($columns);
        }

        $minutes = $this->cacheMinutes ?: config('model-cache.cache_duration', 60);
        $cacheKey = $this->getCacheKey($columns);

        if ($this->eagerLoadUnfingerprintable) {
            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->debug('Eager-load constraint could not be fingerprinted or resolved; bypassing cache.');
            }

            return $this->getWithoutCache($columns);
        }

        $cacheTags = $this->getCacheTags();
        $cache = $this->getCacheDriver();

        try {
            return $this->rememberWithLock($cacheKey, $minutes * 60, function () use ($columns) {
                return $this->getWithoutCache($columns);
            }, $cacheTags);
        } catch (\Throwable $e) {
            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->error("Cache read failed, bypassing cache: {$e->getMessage()}");
            }

            return $this->getWithoutCache($columns);
        }
    }

    /**
     * Set the cache duration.
     *
     * @param  int  $minutes
     * @return $this
     */
    public function remember($minutes)
    {
        // Don't set cache minutes if caching is globally disabled
        if (config('model-cache.enabled', true) === false) {
            return $this->withoutCache();
        }

        $this->cacheMinutes = $minutes;

        return $this;
    }

    /**
     * Disable caching for this query.
     *
     * @return $this
     */
    public function withoutCache()
    {
        $this->cacheMinutes = 0;

        return $this;
    }

    /**
     * Get a unique cache key for the complete query.
     *
     * @param  array  $columns
     * @param  bool  $withEagerLoads  Aggregates never eager-load, so they pass false.
     * @return string
     */
    public function getCacheKey($columns = ['*'], bool $withEagerLoads = true): string
    {
        // This is the prefix defined in our package config
        $configPrefix = $this->cachePrefix ?? config('model-cache.cache_key_prefix', 'model_cache_');

        // Fresh evaluation: a previous key computation must not leave a stale
        // uncacheable verdict behind for this builder.
        $this->eagerLoadUnfingerprintable = false;

        $connection = $this->model->getConnection();

        // Create unique components for our key
        $keyComponents = [
            $configPrefix,
            get_class($this->model),
            $connection->getName(),
            $connection->getDatabaseName(),
            $this->model->getTable(),
            $this->toSql(),
            $this->bindingsFingerprint(),
            json_encode($columns),
            config('model-cache.include_locale_in_key', false) ? app()->getLocale() : null,
        ];

        // Include eager loaded relationships, including any `with()` constraint
        // closures, so two differently-constrained eager loads cannot collide.
        if ($withEagerLoads && count($this->eagerLoad) > 0) {
            $keyComponents[] = 'with:' . $this->eagerLoadFingerprint();
        }

        // serialize() (not implode) so a component can never forge the separator
        // and produce the same concatenation as a different component list.
        $uniqueKey = $this->hashIdentifier(serialize($keyComponents));

        // Add debug logging if enabled
        if (config('model-cache.debug_mode', false)) {
            resolve(ModelCacheDebugger::class)->debug("Generated cache key: {$uniqueKey} for query: {$this->toSql()} with " . count($this->getBindings()) . ' bindings and relations: ' . json_encode(array_keys($this->eagerLoad)));
        }

        // Return only the unique hash - Laravel will handle adding its own prefix
        return $uniqueKey;
    }

    /**
     * Flush the cache for this specific query.
     *
     * This method is called in two primary situations:
     * 1. Explicitly by the user: Model::where('condition', $value)->flushCache();
     * 2. Automatically after mass operations like update(), delete(), etc.
     *
     * The method attempts to clear the cache in three ways, in order of specificity:
     * 1. First, it tries to remove the specific cache key for this query
     * 2. If the cache driver supports tags, it tries to flush by model-specific tags
     * 3. As a fallback, it calls the model's flushModelCache() method
     *
     * @param  array  $columns
     * @return bool
     */
    public function flushQueryCache($columns = ['*']): bool
    {
        $connection = $this->model->getConnection();

        // Defer until the transaction commits so a rollback never invalidates cache.
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(fn() => $this->flushQueryCache($columns));

            return true;
        }

        try {
            $cacheKey = $this->getCacheKey($columns);
            $cache = $this->getCacheDriver();
            $cacheTags = $this->getCacheTags();
            $tagged = $cacheTags && $this->supportsTags($cache);

            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->info("Flushing specific query cache: {$cacheKey}");
            }

            // Tagged stores scope the entry under the tag namespace, so the
            // specific-key forget must use the same tag set it was written with.
            $result = $tagged
                ? $cache->tags($cacheTags)->forget($cacheKey)
                : $cache->forget($cacheKey);

            if ($result && config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->debug("Successfully removed specific cache key: {$cacheKey}");
            }

            if ($tagged) {
                try {
                    $cache->tags($cacheTags)->flush();
                    $result = true;
                    if (config('model-cache.debug_mode', false)) {
                        resolve(ModelCacheDebugger::class)->debug('Successfully flushed cache using tags for model: ' . get_class($this->model));
                    }
                } catch (\Throwable $e) {
                    if (config('model-cache.debug_mode', false)) {
                        resolve(ModelCacheDebugger::class)->debug("Could not flush by tags: {$e->getMessage()}");
                    }
                }
            }

            return $result;
        } catch (\Throwable $e) {
            resolve(ModelCacheDebugger::class)->error("Error flushing query cache: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Alias for flushQueryCache for backward compatibility with existing code.
     *
     * @param  array  $columns
     * @return bool
     */
    public function flushCache($columns = ['*'])
    {
        return $this->flushQueryCache($columns);
    }

    /**
     * Override the get method to automatically use cache.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        // Fast path: caching disabled globally
        if (config('model-cache.enabled', true) === false) {
            return parent::get($columns);
        }

        // Fast path: explicitly disabled for this query
        if (isset($this->cacheMinutes) && $this->cacheMinutes === 0) {
            return $this->getWithoutCache($columns);
        }

        return $this->getFromCache($columns);
    }

    /**
     * Paginate the given query with caching support.
     *
     * @param  int|null  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  int|null  $total
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        // Fast path: caching disabled globally
        if (config('model-cache.enabled', true) === false) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        // Fast path: explicitly disabled for this query
        if (isset($this->cacheMinutes) && $this->cacheMinutes === 0) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        return $this->paginateFromCache($perPage, $columns, $pageName, $page, $total);
    }

    /**
     * Paginate the given query with caching support.
     *
     * @param  int|null  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  int|null  $total
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateFromCache($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        if (config('model-cache.enabled', true) === false) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        // Fast path: explicitly disabled for this query
        if (isset($this->cacheMinutes) && $this->cacheMinutes === 0) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        $page = $page ?: \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $keyComponents = [
            'paginate',
            $perPage,
            $pageName,
            $page,
            json_encode($columns),
            // The rendered paginator embeds its path, so the key must too.
            \Illuminate\Pagination\Paginator::resolveCurrentPath(),
        ];

        // A custom total produces a different paginator, so it must produce a different key
        if ($total !== null) {
            $keyComponents[] = $total;
        }

        $cacheKey = $this->getCacheKey($keyComponents);

        if ($this->eagerLoadUnfingerprintable) {
            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->debug('Eager-load constraint could not be fingerprinted or resolved; bypassing cache.');
            }

            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        $minutes = $this->cacheMinutes ?: config('model-cache.cache_duration', 60);
        $cacheTags = $this->getCacheTags();

        $callback = function () use ($perPage, $columns, $pageName, $page, $total) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        };

        try {
            return $this->rememberWithLock($cacheKey, $minutes * 60, $callback, $cacheTags);
        } catch (\Throwable $e) {
            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->error("Cache read failed for paginate, bypassing cache: {$e->getMessage()}");
            }

            return $callback();
        }
    }

    /**
     * Retrieve the "count" result of the query from cache.
     *
     * @param  string  $columns
     * @return int
     */
    public function countFromCache($columns = '*'): int
    {
        return $this->aggregateFromCache('count', is_array($columns) ? implode(',', $columns) : $columns);
    }

    /**
     * Retrieve the sum of the values of a given column from cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function sumFromCache($column)
    {
        return $this->aggregateFromCache('sum', $column);
    }

    /**
     * Retrieve the maximum value of a given column from cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function maxFromCache($column)
    {
        return $this->aggregateFromCache('max', $column);
    }

    /**
     * Retrieve the minimum value of a given column from cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function minFromCache($column)
    {
        return $this->aggregateFromCache('min', $column);
    }

    /**
     * Retrieve the average of the values of a given column from cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function avgFromCache($column)
    {
        return $this->aggregateFromCache('avg', $column);
    }

    /**
     * Override the count method to automatically use cache.
     *
     * @param  string  $columns
     * @return int
     */
    public function count($columns = '*')
    {
        return $this->countFromCache($columns);
    }

    /**
     * Override the sum method to automatically use cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function sum($column)
    {
        return $this->sumFromCache($column);
    }

    /**
     * Override the max method to automatically use cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->maxFromCache($column);
    }

    /**
     * Override the min method to automatically use cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->minFromCache($column);
    }

    /**
     * Override the avg method to automatically use cache.
     *
     * @param  string  $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->avgFromCache($column);
    }

    /**
     * Alias for the "avg" method.
     *
     * @param  string  $column
     * @return mixed
     */
    public function average($column)
    {
        return $this->avg($column);
    }

    /**
     * Update records in the database and flush cache.
     *
     * @return int
     */
    public function update(array $values)
    {
        return $this->flushAfter(parent::update($values), 'update');
    }

    /**
     * Delete records from the database and flush cache.
     *
     * @return mixed
     */
    public function delete()
    {
        $result = parent::delete();

        // A soft delete routes through update(), which already flushed and marked
        // this model — don't flush a second time. Hard deletes never mark, so a
        // stale marker from an earlier update() on this builder must not suppress
        // their flush.
        $softDeleted = method_exists($this->model, 'runSoftDelete')
            && method_exists($this->model, 'consumeCacheFlushMarker')
            && $this->model::consumeCacheFlushMarker($this->model);

        if ($result && ! $softDeleted) {
            $this->flushAfter($result, 'delete');
        }

        return $result;
    }

    /**
     * Increment a column's value by a given amount and flush cache.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        return $this->flushAfter(parent::increment($column, $amount, $extra), 'increment');
    }

    /**
     * Decrement a column's value by a given amount and flush cache.
     *
     * @param  string  $column
     * @param  float|int  $amount
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return $this->flushAfter(parent::decrement($column, $amount, $extra), 'decrement');
    }

    /**
     * Insert new records into the database and flush cache.
     *
     * @return bool
     */
    public function insert(array $values)
    {
        return $this->flushAfter(parent::insert($values), 'insert');
    }

    /**
     * Insert new records into the database while ignoring errors and flush cache.
     *
     * @return int
     */
    public function insertOrIgnore(array $values)
    {
        return $this->flushAfter(parent::insertOrIgnore($values), 'insertOrIgnore');
    }

    /**
     * Insert a new record and get the value of the primary key and flush cache.
     *
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        return $this->flushAfter(parent::insertGetId($values, $sequence), 'insertGetId');
    }

    /**
     * Insert or update a record matching the attributes, and fill it with values.
     *
     * @param  array  $attributes
     * @param  array|callable  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        return $this->flushAfter(parent::updateOrInsert($attributes, $values), 'updateOrInsert');
    }

    /**
     * Insert new records or update the existing ones and flush cache.
     *
     * @param  array|string  $uniqueBy
     * @param  array|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return $this->flushAfter(parent::upsert($values, $uniqueBy, $update), 'upsert');
    }

    /**
     * Increment several columns' values and flush cache.
     *
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     * @return mixed
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        return $this->flushAfter(parent::incrementEach($columns, $extra), 'incrementEach');
    }

    /**
     * Decrement several columns' values and flush cache.
     *
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     * @return mixed
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        return $this->flushAfter(parent::decrementEach($columns, $extra), 'decrementEach');
    }

    /**
     * Insert records using a subquery and flush cache.
     *
     * @param  array  $columns
     * @param  mixed  $query
     * @return int
     */
    public function insertUsing(array $columns, $query)
    {
        return $this->flushAfter(parent::insertUsing($columns, $query), 'insertUsing');
    }

    /**
     * Insert records using a subquery while ignoring errors, and flush cache.
     *
     * @param  array  $columns
     * @param  mixed  $query
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        return $this->flushAfter(parent::insertOrIgnoreUsing($columns, $query), 'insertOrIgnoreUsing');
    }

    /**
     * Update records using a FROM clause and flush cache.
     *
     * @return mixed
     */
    public function updateFrom(array $values)
    {
        return $this->flushAfter(parent::updateFrom($values), 'updateFrom');
    }

    /**
     * Truncate the table and flush cache.
     *
     * @return void
     */
    public function truncate()
    {
        parent::truncate();

        $this->flushAfter(true, 'truncate');
    }

    /**
     * Force a hard delete on a soft deleted model and flush cache.
     * This method overrides the forceDelete method present in the SoftDeletes trait.
     *
     * @return mixed
     */
    public function forceDelete()
    {
        // Check if the model uses SoftDeletes
        if (! method_exists($this->model, 'runSoftDelete')) {
            return $this->delete();
        }

        return $this->flushAfter(parent::forceDelete(), 'forceDelete');
    }

    /**
     * Restore soft deleted models and flush cache.
     * This method overrides the restore method present in the SoftDeletes trait.
     *
     * @return mixed
     */
    public function restore()
    {
        // Check if the model uses SoftDeletes
        if (! method_exists($this->model, 'runSoftDelete')) {
            return 0;
        }

        $result = parent::restore();

        // restore() goes through update(), which already flushed and marked this
        // model — only flush if it somehow didn't.
        if ($result && ! (method_exists($this->model, 'consumeCacheFlushMarker') && $this->model::consumeCacheFlushMarker($this->model))) {
            return $this->flushAfter($result, 'restore');
        }

        return $result;
    }

    /**
     * Execute a cache read with optional stampede prevention locking.
     */
    protected function rememberWithLock(string $cacheKey, int $seconds, callable $callback, ?array $cacheTags = null)
    {
        if (! config('model-cache.use_cache_locks', false)) {
            if ($cacheTags && $this->supportsTags($this->getCacheDriver())) {
                return $this->getCacheDriver()->tags($cacheTags)->remember($cacheKey, $seconds, $callback);
            }

            return $this->getCacheDriver()->remember($cacheKey, $seconds, $callback);
        }

        $cache = $this->getCacheDriver();
        $lockKey = "stampede:{$cacheKey}";
        $lockDuration = $this->cacheLockSeconds ?? config('model-cache.cache_lock_seconds', 5);

        // Try to get existing cached value first
        try {
            if ($cacheTags && $this->supportsTags($cache)) {
                $cached = $cache->tags($cacheTags)->get($cacheKey);
            } else {
                $cached = $cache->get($cacheKey);
            }

            if ($cached !== null) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache miss or error — proceed to lock
        }

        // Acquire lock to prevent stampede
        $lock = $cache->lock($lockKey, $lockDuration);

        if ($lock->get()) {
            try {
                $result = $callback();

                if ($cacheTags && $this->supportsTags($cache)) {
                    $cache->tags($cacheTags)->put($cacheKey, $result, $seconds);
                } else {
                    $cache->put($cacheKey, $result, $seconds);
                }

                return $result;
            } finally {
                $lock->release();
            }
        }

        // Another request is computing — poll for the result until the lock
        // duration elapses, then fall back to executing the query directly.
        $deadline = microtime(true) + $lockDuration;

        while (microtime(true) < $deadline) {
            usleep(50000); // 50ms

            try {
                $cached = $cacheTags && $this->supportsTags($cache)
                    ? $cache->tags($cacheTags)->get($cacheKey)
                    : $cache->get($cacheKey);
            } catch (\Throwable $e) {
                // If the re-read fails, just execute the query directly
                return $callback();
            }

            if ($cached !== null) {
                return $cached;
            }
        }

        // The lock holder is still computing past the lock duration — execute
        // the query directly instead of returning null to a caller expecting a Collection.
        return $callback();
    }

    /**
     * Execute an aggregate query with caching support.
     *
     * @param  string  $method  The aggregate method name (count, sum, min, max, avg)
     * @param  string  $column  The column to aggregate
     * @return mixed
     */
    protected function aggregateFromCache(string $method, string $column)
    {
        if (config('model-cache.enabled', true) === false) {
            return parent::{$method}($column);
        }

        // Explicit cache bypass for this query
        if (isset($this->cacheMinutes) && $this->cacheMinutes === 0) {
            return parent::{$method}($column);
        }

        $cacheKey = $this->getCacheKey([$method, $column], false);
        $minutes = $this->cacheMinutes ?: config('model-cache.cache_duration', 60);
        $cacheTags = $this->getCacheTags();

        $callback = fn() => parent::{$method}($column);

        try {
            return $this->rememberWithLock($cacheKey, $minutes * 60, $callback, $cacheTags);
        } catch (\Throwable $e) {
            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->error("Cache read failed for {$method}, bypassing cache: {$e->getMessage()}");
            }

            return $callback();
        }
    }

    /**
     * Check if the cache driver supports tags.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     * @return bool
     */
    protected function supportsTags($cache)
    {
        try {
            return method_exists($cache, 'supportsTags') && $cache->supportsTags();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Flush the cache after a mass operation when it affected rows.
     *
     * @param  mixed  $result
     * @return mixed
     */
    protected function flushAfter($result, string $operation)
    {
        if ($result) {
            if (config('model-cache.debug_mode', false)) {
                resolve(ModelCacheDebugger::class)->info("Flushing cache after `{$operation}` operation for model: " . get_class($this->model));
            }

            $this->flushModelOrQueryCache();
        }

        return $result;
    }

    /**
     * Flush model cache when available, otherwise flush query cache.
     */
    protected function flushModelOrQueryCache(): void
    {
        $flush = function () {
            if (method_exists($this->model, 'flushModelCache')) {
                $this->model->flushModelCache();

                // Instance operations (delete/forceDelete) run through this builder first
                // and then fire the `deleted` event; mark the instance so the event handler
                // skips its redundant flush.
                if (method_exists($this->model, 'markCacheFlushed')) {
                    $this->model->markCacheFlushed($this->model);
                }
            } else {
                $this->flushQueryCache();
            }
        };

        $connection = $this->model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($flush);
        } else {
            $flush();
        }
    }

    /**
     * Hash a value for cache identifiers.
     *
     * Uses configured hashing algorithm (default: xxh128).
     */
    protected function hashIdentifier(string $value): string
    {
        $algorithm = strtolower((string) config('model-cache.hash_algorithm', 'xxh128'));

        try {
            return hash($algorithm, $value);
        } catch (\ValueError $e) {
            // Keep the exception type consistent with the provider's boot-time validation
            throw new \InvalidArgumentException(
                "Invalid model-cache.hash_algorithm '{$algorithm}'. Available algorithms: " . implode(', ', array_slice(hash_algos(), 0, 10)) . '...',
                previous: $e,
            );
        }
    }

    /**
     * Fingerprint the query bindings losslessly. json_encode() collapses distinct
     * invalid-UTF-8/binary bindings into the same replacement characters, so string
     * bindings are base64-encoded first.
     */
    protected function bindingsFingerprint($bindings = null): string
    {
        $bindings = array_map(
            fn($binding) => is_string($binding) ? 'b64:' . base64_encode($binding) : $binding,
            $bindings ?? $this->getBindings(),
        );

        return (string) json_encode($bindings, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Build a stable fingerprint for the eager-load constraints on this query.
     *
     * Laravel wraps every `with()` constraint in a combineConstraints() closure,
     * so we walk captured variables to reach the user's closure. When a capture
     * cannot be fingerprinted, the constraint is resolved against a fresh relation
     * and the resulting relation state is fingerprinted instead. Only if that also
     * fails does the query fall back to bypassing the cache.
     */
    protected function eagerLoadFingerprint(): string
    {
        $fingerprints = $this->fingerprintEagerLoads($this);

        // Eager-load insertion order must not change the key.
        ksort($fingerprints);

        return (string) json_encode($fingerprints, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Fingerprint a builder's eager loads, grouped by root relation so a dotted
     * name shares one resolution with its root.
     *
     * @return array<string, string>
     */
    protected function fingerprintEagerLoads($builder, int $depth = 0): array
    {
        $groups = [];

        foreach ($builder->getEagerLoads() as $name => $constraint) {
            $root = str_contains($name, '.') ? strstr($name, '.', true) : $name;
            $groups[$root][$name] = $constraint;
        }

        $fingerprints = [];

        foreach ($groups as $root => $entries) {
            try {
                $parts = [];

                foreach ($entries as $name => $constraint) {
                    $parts[] = $name . '=' . $this->valueFingerprint($constraint, $depth);
                }

                $fingerprints[$root] = implode(';', $parts);
            } catch (UnfingerprintableEagerLoadException $e) {
                $fingerprints[$root] = 'resolved:' . $this->resolveEagerLoadGroup($builder, $root, $entries, $depth);
            }
        }

        return $fingerprints;
    }

    /**
     * Resolve an un-fingerprintable eager-load group against a fresh relation and
     * fingerprint the resulting relation state. Falls back to an uncacheable
     * verdict if the relation cannot be built or the constraint throws.
     *
     * @param  array<string, \Closure>  $entries
     */
    protected function resolveEagerLoadGroup($builder, string $root, array $entries, int $depth): string
    {
        try {
            $relation = $builder->getRelation($root);

            foreach ($entries as $name => $constraint) {
                // Dotted entries are applied to nested relations by getRelation();
                // only the root's own constraint runs here.
                if ($constraint instanceof \Closure && ! str_contains($name, '.')) {
                    $constraint($relation);
                }
            }

            return $this->relationStateFingerprint($relation, $depth + 1);
        } catch (\Throwable $e) {
            $this->eagerLoadUnfingerprintable = true;

            return 'unresolvable';
        }
    }

    /**
     * Fingerprint the complete state of a resolved relation: its underlying query
     * (SQL + bindings), any nested eager loads, and its own relation-level
     * properties (pivot columns/values, morph settings, defaults, …).
     */
    protected function relationStateFingerprint($relation, int $depth): string
    {
        $query = $relation->getQuery();

        $parts = [
            $query->toSql(),
            $this->bindingsFingerprint($query->getBindings()),
        ];

        if (count($query->getEagerLoads()) > 0) {
            $nested = $this->fingerprintEagerLoads($query, $depth + 1);
            ksort($nested);
            $parts[] = 'with:' . json_encode($nested, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $props = get_mangled_object_vars($relation);

        foreach ($props as $key => $value) {
            // Protected/private props are NUL-mangled ("\0*\0query"); strip to the
            // bare name so we can skip the objects that are not relation state.
            $name = ($pos = strrpos($key, "\0")) === false ? $key : substr($key, $pos + 1);

            if (in_array($name, ['query', 'parent', 'related'], true)) {
                continue;
            }

            // A nested builder (e.g. a one-of-many subquery on latestOfMany/ofMany)
            // cannot be serialized; its state is already reflected in the relation
            // query's SQL, so skip it rather than fail the whole resolution.
            if ($value instanceof Builder || $value instanceof \Illuminate\Database\Query\Builder) {
                continue;
            }

            $parts[] = $name . '=' . $this->valueFingerprint($value, $depth + 1);
        }

        return implode(',', $parts);
    }

    /**
     * Fingerprint a captured value (closure, array, object or scalar).
     *
     * @param  mixed  $value
     */
    protected function valueFingerprint($value, int $depth): string
    {
        if ($depth > 6) {
            throw new UnfingerprintableEagerLoadException('Capture nesting too deep to fingerprint.');
        }

        if (is_resource($value)) {
            throw new UnfingerprintableEagerLoadException('Capture contains a resource, which cannot be fingerprinted.');
        }

        if ($value instanceof \Closure) {
            return $this->closureFingerprint($value, $depth);
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = $key . ':' . $this->valueFingerprint($item, $depth + 1);
            }

            return '[' . implode(',', $parts) . ']';
        }

        if (is_object($value)) {
            return get_class($value) . ':' . $this->objectFingerprint($value, $depth);
        }

        // base64 keeps invalid-UTF-8 strings lossless (json substitutes every bad
        // byte with the same replacement char, collapsing distinct captures).
        if (is_string($value)) {
            return 'str:' . base64_encode($value);
        }

        return gettype($value) . ':' . var_export($value, true);
    }

    /**
     * Fingerprint an object by its full (including private) serialized state,
     * hashed with the configured algorithm (xxh128 by default).
     *
     * ponytail: serialize() maps a resource handle to `i:0` without throwing, so
     * two objects differing only by a held resource still collide. Don't capture
     * resource-bearing objects as eager-load constraint state.
     *
     * @param  object  $value
     */
    protected function objectFingerprint($value, int $depth): string
    {
        if ($depth > 6) {
            throw new UnfingerprintableEagerLoadException('Capture nesting too deep to fingerprint.');
        }

        try {
            return 'ser:' . $this->hashIdentifier(serialize($value));
        } catch (\Throwable $e) {
            // Unserializable state (e.g. a captured closure) cannot be fingerprinted
            // from code identity; the caller will resolve the constraint instead.
            throw new UnfingerprintableEagerLoadException('Capture contains unserializable state: ' . get_class($value), 0, $e);
        }
    }

    /**
     * Fingerprint a closure by code location, captured values and any bound
     * `$this` state, memoized per closure instance for this builder's lifetime.
     */
    protected function closureFingerprint(\Closure $closure, int $depth): string
    {
        $this->closureFingerprints ??= new \WeakMap();

        if (isset($this->closureFingerprints[$closure])) {
            return $this->closureFingerprints[$closure];
        }

        try {
            $reflection = new \ReflectionFunction($closure);

            $parts = [
                $this->normalizeFilePath((string) $reflection->getFileName()),
                (string) $reflection->getStartLine(),
                (string) $reflection->getEndLine(),
            ];

            foreach ($reflection->getStaticVariables() as $name => $variable) {
                $parts[] = $name . '=' . $this->valueFingerprint($variable, $depth + 1);
            }

            // A bound object may carry state the constraint reads ($this->...).
            // Skip Laravel's combineConstraints() wrapper, whose $this is the query
            // builder; fingerprinting it would poison every key with query state.
            $bound = $reflection->getClosureThis();

            if ($bound !== null && ! $bound instanceof Builder) {
                $parts[] = 'this=' . $this->objectFingerprint($bound, $depth + 1);
            }

            $fingerprint = implode(',', $parts);
        } catch (\Throwable $e) {
            throw new UnfingerprintableEagerLoadException('Closure could not be fingerprinted.', 0, $e);
        }

        return $this->closureFingerprints[$closure] = $fingerprint;
    }

    /**
     * Normalize a file path so cache keys stay stable across deploy directories.
     */
    protected function normalizeFilePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        try {
            $base = str_replace('\\', '/', base_path()) . '/';

            if (str_starts_with($path, $base)) {
                return substr($path, strlen($base));
            }
        } catch (\Throwable $e) {
            // No application bound (e.g. bare PHPUnit) — use the path as-is.
        }

        return $path;
    }

    /**
     * Get the cache tags for the query.
     *
     * @return array
     */
    protected function getCacheTags()
    {
        return [
            'model_cache',
            get_class($this->model),
            $this->model->getTable(),
        ];
    }

    /**
     * Get the models without cache.
     *
     * @param  array  $columns
     * @return Collection
     */
    protected function getWithoutCache($columns = ['*'])
    {
        return parent::get($columns);
    }

    /**
     * Get the cache driver to use.
     *
     * @return Repository
     */
    protected function getCacheDriver()
    {
        if ($this->cacheDriver !== null) {
            return $this->cacheDriver;
        }

        try {
            $cacheStore = config('model-cache.cache_store');

            if ($cacheStore) {
                $this->cacheDriver = Cache::store($cacheStore);
            } else {
                $this->cacheDriver = Cache::store();
            }
        } catch (\Throwable $e) {
            // Surface a misconfigured store instead of silently using another one,
            // then fall back so reads don't break.
            resolve(ModelCacheDebugger::class)->warning("Configured model cache store is unavailable ({$e->getMessage()}); falling back to the default cache store.");
            $this->cacheDriver = Cache::store(config('cache.default'));
        }

        return $this->cacheDriver;
    }
}
