<?php

namespace Daika7ana\LaravelModelCache;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * @property ?int $cacheMinutes
 * @property ?string $cachePrefix
 * @property ?int $cacheLockSeconds
 */
trait HasCachedQueries
{
    /**
     * The model instance whose cache was just flushed by a builder override or the
     * `updated` event handler. The `deleted`/`restored` event handlers consume this
     * marker to skip their redundant flush (see markCacheFlushed()).
     *
     * Held weakly so the marker never pins a model graph for the process lifetime.
     * A collected reference reads as "no marker" — worst case one redundant flush.
     *
     * @var \WeakReference|null
     */
    private static ?\WeakReference $cacheFlushedModel = null;

    /**
     * Whether the missing-tags warning was already logged in this process.
     * Without it, every write on a non-taggable store logs the same line.
     *
     * @var bool
     */
    private static bool $warnedMissingTags = false;

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
                // Defer flush until this model's own connection commits, so a
                // rollback (or a transaction on another connection) never flushes.
                $connection = $model->getConnection();

                $flush = function () use ($model, $event, $debugger, $connection) {
                    // The builder override just flushed this exact instance — skip the
                    // redundant event-side flush.
                    if (in_array($event, ['deleted', 'restored'], true)) {
                        if (static::consumeCacheFlushMarker($model)) {
                            return;
                        }
                    } elseif (static::hasCacheFlushMarker($model)) {
                        return;
                    }

                    static::flushModelCache($event, $connection);
                    $debugger->info("Cache flushed after `{$event}` for model: " . get_class($model));
                };

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
        static::$cacheFlushedModel = \WeakReference::create($model);
    }

    /**
     * Check the flush marker without consuming it. Used by the `created`/`updated`
     * handlers so a following `restored` event still sees the marker.
     *
     * @internal
     */
    public static function hasCacheFlushMarker(Model $model): bool
    {
        return static::$cacheFlushedModel?->get() === $model;
    }

    /**
     * Consume the flush marker when it matches the given model instance. Used by the
     * `deleted`/`restored` handlers, which are the last event of their operation.
     *
     * @internal
     */
    public static function consumeCacheFlushMarker(Model $model): bool
    {
        if (static::$cacheFlushedModel?->get() === $model) {
            static::$cacheFlushedModel = null;

            return true;
        }

        return false;
    }

    /**
     * Static method to flush cache for the model.
     * This allows calling Model::flushModelCache() directly without an instance.
     *
     * @param  string|null  $operation
     * @param  \Illuminate\Database\Connection|null  $connection  The connection the write ran on; deferral keys off it. Static callers without one fall back to the model's default connection.
     * @return bool
     */
    public static function flushModelCache(?string $operation = null, ?Connection $connection = null): bool
    {
        // Defer until the connection the write ran on commits, so a rollback
        // never invalidates cache. A static call has no instance to consult,
        // so it can only guess the model's default connection.
        $connection ??= (new static())->getConnection();

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
     * Flush the cache for this model.
     *
     * @return bool
     */
    public function flushCache()
    {
        return self::flushModelCache(null, $this->getConnection());
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
                } catch (\Throwable $e) {
                    $debugger->error("Error flushing cache with tags for model {$modelClass}: {$e->getMessage()}");

                    // A real flush failure is not a missing-tags store — don't
                    // emit the misleading warning or latch the flag.
                    return false;
                }
            }

            // Without tags, we cannot safely flush only this model's cache.
            // Flushing the entire cache would destroy sessions, auth tokens, etc.
            // The condition is identical for every write, so warn once per process.
            if (! self::$warnedMissingTags) {
                self::$warnedMissingTags = true;
                $debugger->warning("Cache driver does not support tags. Model cache invalidation requires Redis or Memcached. Skipping flush for model: {$modelClass}");
            }

            return false;

        } catch (\Throwable $e) {
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
        return CacheStoreResolver::resolve();
    }
}
