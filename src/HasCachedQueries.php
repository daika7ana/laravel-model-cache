<?php

namespace YMigVal\LaravelModelCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * @property ?int $cacheMinutes
 * @property ?string $cachePrefix
 */
trait HasCachedQueries
{
    /**
     * Cached model table names by class.
     *
     * @var array<string, string>
     */
    protected static array $modelTableCache = [];

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  Builder  $query
     * @return CacheableBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new CacheableBuilder(
            $query,
            $this->cacheMinutes ?? null,
            $this->cachePrefix ?? null,
        );
    }

    /**
     * Boot the trait.
     *
     * This method registers event handlers for individual model operations that trigger Eloquent events:
     * - created: When a new model is created via Model::create() or $model->save() on a new instance
     * - updated: When an existing model is updated via $model->save() or $model->update()
     * - saved: When a model is created or updated via $model->save()
     * - deleted: When a model is deleted via $model->delete()
     * - restored: When a soft-deleted model is restored via $model->restore()
     *
     * NOTE: Mass operations that don't retrieve models first (like Model::where(...)->update() or
     * Model::where(...)->delete()) do not trigger these events. For these operations, the CacheableBuilder
     * class overrides methods like update(), delete(), insert(), insertGetId(), insertOrIgnore(),
     * updateOrInsert(), upsert(), truncate(), increment(), decrement(), forceDelete(), and restore()
     * to ensure cache is properly invalidated in all scenarios.
     *
     * @return void
     */
    public static function bootHasCachedQueries()
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event) {
                static::flushModelCache();

                resolve(ModelCacheDebugger::class)->info("Cache flushed after {$event} for model: " . get_class($model));
            });
        }

        static::registerModelEvent('restored', function (Model $model) {
            static::flushModelCache();

            resolve(ModelCacheDebugger::class)->info('Cache flushed after restoration for model: ' . get_class($model));
        });
    }

    /**
     * Get model cache context values.
     *
     * @return array{0: string, 1: string}
     */
    protected static function getModelCacheContext(): array
    {
        $modelClass = static::class;

        if (! isset(self::$modelTableCache[$modelClass])) {
            $model = new static;
            self::$modelTableCache[$modelClass] = $model->getTable();
        }

        return [$modelClass, self::$modelTableCache[$modelClass]];
    }

    /**
     * Static method to flush cache for the model.
     * This allows calling Model::flushModelCache() directly without an instance.
     *
     * @return bool
     */
    public static function flushModelCache()
    {
        try {
            [$modelClass, $tableName] = static::getModelCacheContext();

            // Get the cache driver directly
            $cache = self::getStaticCacheDriver();
            $debugger = resolve(ModelCacheDebugger::class);

            // Set tags for this model
            $tags = [
                'model_cache',
                $modelClass,
                $tableName,
            ];

            // Try with tags if supported
            if (method_exists($cache, 'supportsTags') && $cache->supportsTags()) {
                try {
                    $result = $cache->tags($tags)->flush();
                    $debugger->info("Cache flushed statically for model: {$modelClass}");

                    return $result;
                } catch (\Exception $e) {
                    $debugger->error("Error flushing cache with tags for model {$modelClass}: {$e->getMessage()}");
                    // Continue to fallback method if tags fail
                }
            }

            // Fallback to flush the entire cache
            $result = $cache->flush();
            $debugger->info("Entire cache flushed for model: {$modelClass}");

            return $result;

        } catch (\Exception $e) {
            $debugger->error('Error in flushCacheStatic for model ' . static::class . ": {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Allow flushing specific query cache when used directly in a query chain.
     * This method is intended to be used as:
     * Model::where('condition', $value)->flushCache();
     *
     * @return bool
     */
    public function scopeFlushCache($query)
    {
        if (method_exists($query, 'flushQueryCache')) {
            return $query->flushQueryCache();
        }

        // Fallback to flushing the entire model cache
        return $this->flushCache();
    }

    /**
     * Flush the cache for this model.
     *
     * @return bool
     */
    public function flushCache()
    {
        return self::flushModelCache();
    }

    /**
     * Get a static instance of the cache driver.
     * This allows static methods to use the cache without creating a full model instance.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected static function getStaticCacheDriver()
    {
        try {
            $cacheStore = config('model-cache.cache_store');

            if ($cacheStore) {
                return \Illuminate\Support\Facades\Cache::store($cacheStore);
            }

            return \Illuminate\Support\Facades\Cache::store();
        } catch (\Exception $e) {
            // If there's an issue with the configured cache driver,
            // fall back to the default driver
            resolve(ModelCacheDebugger::class)->error("Error getting cache driver: {$e->getMessage()}");

            return \Illuminate\Support\Facades\Cache::store(config('cache.default'));
        }
    }

    /**
     * Determine if cache driver supports tags.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     * @return bool
     */
    protected function supportsTags($cache)
    {
        try {
            return method_exists($cache, 'supportsTags') && $cache->supportsTags();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the cache driver to use.
     *
     * @return Repository
     */
    protected function getCacheDriver()
    {
        return self::getStaticCacheDriver();
    }
}
