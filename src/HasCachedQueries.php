<?php

namespace Daika7ana\LaravelModelCache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Daika7ana\LaravelModelCache\Contracts\CacheableBuilderContract;

/**
 * @property ?int $cacheMinutes
 * @property ?string $cachePrefix
 */
trait HasCachedQueries
{
    /**
     * The model instance whose cache was just flushed by a builder override or the
     * `updated` event handler. The `deleted`/`restored` event handlers consume this
     * marker to skip their redundant flush (see markCacheFlushed()).
     *
     * @var Model|null
     */
    private static ?Model $cacheFlushedModel = null;

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
            $this->cacheLockSeconds ?? null,
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
     * Instance operations flush once through the CacheableBuilder override (which marks the
     * instance) and then fire an event for the same instance; the event handlers skip their
     * redundant flush via the marker. `deleted`/`restored` consume the marker, `created`/
     * `updated` only peek, because restoring a model saves it and fires `updated` followed by
     * `restored` (both of which must skip).
     *
     * @return void
     */
    public static function bootHasCachedQueries()
    {
        $debugger = resolve(ModelCacheDebugger::class);

        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event, $debugger) {
                $flush = function () use ($model, $event, $debugger) {
                    // The builder override just flushed this exact instance — skip the
                    // redundant event-side flush.
                    if (in_array($event, ['deleted', 'restored'], true)) {
                        if (static::consumeCacheFlushMarker($model)) {
                            return;
                        }
                    } elseif (static::hasCacheFlushMarker($model)) {
                        return;
                    }

                    static::flushModelCache($event);
                    $debugger->info("Cache flushed after `{$event}` for model: " . get_class($model));
                };

                // Defer flush until this model's own connection commits, so a
                // rollback (or a transaction on another connection) never flushes.
                $connection = $model->getConnection();

                if ($connection->transactionLevel() > 0) {
                    $connection->afterCommit($flush);
                } else {
                    $flush();
                }
            });
        }
    }

    /**
     * Record that the cache for this model instance was just flushed by a builder
     * override, so the event handlers can skip their redundant flush.
     *
     * @internal
     */
    public static function markCacheFlushed(Model $model): void
    {
        static::$cacheFlushedModel = $model;
    }

    /**
     * Check the flush marker without consuming it. Used by the `created`/`updated`
     * handlers so a following `restored` event still sees the marker.
     *
     * @internal
     */
    public static function hasCacheFlushMarker(Model $model): bool
    {
        return static::$cacheFlushedModel === $model;
    }

    /**
     * Consume the flush marker when it matches the given model instance. Used by the
     * `deleted`/`restored` handlers, which are the last event of their operation.
     *
     * @internal
     */
    public static function consumeCacheFlushMarker(Model $model): bool
    {
        if (static::$cacheFlushedModel === $model) {
            static::$cacheFlushedModel = null;

            return true;
        }

        return false;
    }

    /**
     * Static method to flush cache for the model.
     * This allows calling Model::flushModelCache() directly without an instance.
     *
     * @return bool
     */
    public static function flushModelCache(?string $operation = null): bool
    {
        $connection = (new static())->getConnection();

        // Defer until the model's connection commits so a rollback never invalidates cache.
        // The deferred callback must flush directly: the testing transactions manager
        // runs afterCommit() callbacks immediately while its wrapping transaction is
        // still open, so re-entering flushModelCache() here would recurse forever.
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(static fn() => static::performModelCacheFlush($operation));

            return true;
        }

        return static::performModelCacheFlush($operation);
    }

    /**
     * Allow flushing specific query cache when used directly in a query chain.
     * This method is intended to be used as:
     * Model::where('condition', $value)->flushCache();
     *
     * @return bool
     */
    public function scopeFlushCache(CacheableBuilderContract $query)
    {
        return $query->flushQueryCache();
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
     * Flush the cache for this model on its current connection.
     *
     * @return bool
     */
    protected static function performModelCacheFlush(?string $operation = null): bool
    {
        $debugger = resolve(ModelCacheDebugger::class);

        try {
            $modelClass = static::class;
            $tableName = (new static())->getTable();

            // Get the cache driver directly
            $cache = self::getStaticCacheDriver();

            // Reset only this model's tags. Including the shared 'model_cache'
            // tag would reset every model's namespace (TagSet::reset() bumps
            // each tag), defeating selective invalidation.
            $tags = [
                $modelClass,
                $tableName,
            ];

            // A delete can cascade to rows of other models without firing their
            // events, so destructive writes must also reset the shared tag.
            if (in_array($operation, ['delete', 'forceDelete', 'truncate', 'deleted'], true)) {
                $tags[] = 'model_cache';
            }

            // Try with tags if supported
            if (method_exists($cache, 'supportsTags') && $cache->supportsTags()) {
                try {
                    $result = $cache->tags($tags)->flush();
                    $debugger->info("Cache flushed statically for model: {$modelClass}");

                    return $result;
                } catch (\Exception $e) {
                    $debugger->error("Error flushing cache with tags for model {$modelClass}: {$e->getMessage()}");
                }
            }

            // Without tags, we cannot safely flush only this model's cache.
            // Flushing the entire cache would destroy sessions, auth tokens, etc.
            $debugger->warning("Cache driver does not support tags. Model cache invalidation requires Redis or Memcached. Skipping flush for model: {$modelClass}");

            return false;

        } catch (\Exception $e) {
            $debugger->error('Error in flushCacheStatic for model ' . static::class . ": {$e->getMessage()}");

            return false;
        }
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
}
