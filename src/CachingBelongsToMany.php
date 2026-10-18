<?php

namespace Daika7ana\LaravelModelCache;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A belongsToMany relation that flushes the parent model's cache after a pivot
 * change. attach()/detach()/updateExistingPivot() are the only write paths that
 * change pivot rows — sync()/syncWithoutDetaching() route through them.
 */
class CachingBelongsToMany extends BelongsToMany
{
    /**
     * True while a batched sync() performs its per-id writes.
     */
    protected $flushSuppressed = false;

    /**
     * Attach a model to the parent.
     *
     * @param  mixed  $id
     * @param  bool  $touch
     * @return void
     */
    public function attach($id, array $attributes = [], $touch = true)
    {
        if (empty($this->parseIds($id))) {
            return;
        }

        parent::attach($id, $attributes, $touch);

        if (! $this->flushSuppressed) {
            $this->flushCache('attach');
        }
    }

    /**
     * Detach models from the relationship.
     *
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return int
     */
    public function detach($ids = null, $touch = true)
    {
        $result = parent::detach($ids, $touch);

        if ($result && ! $this->flushSuppressed) {
            $this->flushCache('detach');
        }

        return $result;
    }

    /**
     * Update an existing pivot record on the table.
     *
     * @param  mixed  $id
     * @param  bool  $touch
     * @return int
     */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);

        if ($result && ! $this->flushSuppressed) {
            $this->flushCache('updateExistingPivot');
        }

        return $result;
    }

    /**
     * Sync the intermediate tables with a list of IDs or collection of models.
     *
     * @param  mixed  $ids
     * @param  bool  $detaching
     * @return array
     */
    public function sync($ids, $detaching = true)
    {
        $this->flushSuppressed = true;

        try {
            $changes = parent::sync($ids, $detaching);
        } finally {
            $this->flushSuppressed = false;
        }

        if ($changes['attached'] || $changes['detached'] || $changes['updated']) {
            $this->flushCache('sync');
        }

        return $changes;
    }

    /**
     * Flush the parent model's cache after a pivot operation.
     *
     * @param  string  $operation
     * @return void
     */
    protected function flushCache($operation)
    {
        $flush = function () use ($operation) {
            if (method_exists($this->parent, 'flushModelCache')) {
                $this->parent->flushModelCache();
            } elseif (method_exists($this->parent, 'flushCache')) {
                $this->parent->flushCache();
            } else {
                throw new \Exception('The parent model must have a flushCache() or flushModelCache() method defined. Make sure your model uses the HasCachedQueries trait. The ModelRelationships trait should be used in conjunction with the HasCachedQueries trait. See the documentation for more information.');
            }

            // A pivot write changes results cached from both sides of the
            // relation, not just the parent's.
            $related = $this->getRelated();

            if ($related !== $this->parent && method_exists($related, 'flushModelCache')) {
                $related->flushModelCache();
            }

            $this->flushPivotTag();

            resolve(ModelCacheDebugger::class)->info("Cache flushed after {$operation} operation for model: " . get_class($this->parent));
        };

        $connection = $this->parent->getConnection();

        // Defer flush until the model's connection commits to avoid flushing on rollback
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($flush);
        } else {
            $flush();
        }
    }

    /**
     * Reset the pivot table's tag so cached eager loads and relation queries on
     * either side see the changed pivot rows.
     */
    protected function flushPivotTag(): void
    {
        try {
            $cache = \Illuminate\Support\Facades\Cache::store(config('model-cache.cache_store') ?: null);

            if (method_exists($cache, 'supportsTags') && $cache->supportsTags()) {
                $cache->tags([$this->getTable()])->flush();
            }
        } catch (\Throwable $e) {
            resolve(ModelCacheDebugger::class)->warning('Could not flush pivot cache tag: ' . $e->getMessage());
        }
    }
}
