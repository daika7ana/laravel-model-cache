<?php

namespace YMigVal\LaravelModelCache;

/**
 * Helper trait to flush cache when relationship methods are called.
 *
 * This trait can be used alongside HasCachedQueries to ensure that
 * operations on model relationships also flush the cache appropriately.
 *
 * @method newRelatedInstance(string $related)
 * @method getForeignKey()
 * @method joiningTable(string $related)
 * @method getKeyName()
 */
trait HasCachedRelationships
{
    /**
     * Override the belongsToMany relationship method to return a custom
     * relationship class that handles cache flushing after operations.
     *
     * @param  string  $related
     * @param  string|null  $table
     * @param  string|null  $foreignPivotKey
     * @param  string|null  $relatedPivotKey
     * @param  string|null  $parentKey
     * @param  string|null  $relatedKey
     * @param  string|null  $relation
     * @return \YMigVal\LaravelModelCache\CachingBelongsToMany
     */
    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null,
        $parentKey = null, $relatedKey = null, $relation = null)
    {
        // Get the original relationship instance
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // Determine the relationship name if not provided
        if (is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // Generate table name if not provided
        if (is_null($table)) {
            $table = $this->joiningTable($related);
        }

        // Create our caching BelongsToMany relationship
        return new CachingBelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
            $this
        );
    }

    /**
     * Override the belongsToMany relation's sync method to flush cache.
     *
     * @param  string  $relation
     * @param  bool  $detaching
     * @return array
     */
    public function syncRelationshipAndFlushCache($relation, array $ids, $detaching = true)
    {
        if (! method_exists($this, $relation)) {
            throw new \BadMethodCallException("Method {$relation} does not exist.");
        }

        $result = $this->$relation()->sync($ids, $detaching);

        $changes = count($result['attached'] ?? []) + count($result['updated'] ?? []) + count($result['detached'] ?? []);

        if ($changes > 0) {
            $this->flushRelationshipCache('sync');
        }

        return $result;
    }

    /**
     * Override the belongsToMany relation's attach method to flush cache.
     *
     * @param  string  $relation
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return void
     */
    public function attachRelationshipAndFlushCache($relation, $ids, array $attributes = [], $touch = true)
    {
        if (! method_exists($this, $relation)) {
            throw new \BadMethodCallException("Method {$relation} does not exist.");
        }

        if (empty((array) $ids)) {
            return;
        }

        $this->$relation()->attach($ids, $attributes, $touch);

        $this->flushRelationshipCache('attach');
    }

    /**
     * Override the belongsToMany relation's detach method to flush cache.
     *
     * @param  string  $relation
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return int
     */
    public function detachRelationshipAndFlushCache($relation, $ids = null, $touch = true)
    {
        if (! method_exists($this, $relation)) {
            throw new \BadMethodCallException("Method {$relation} does not exist.");
        }

        $result = $this->$relation()->detach($ids, $touch);

        if ($result > 0) {
            $this->flushRelationshipCache('detach');
        }

        return $result;
    }

    /**
     * Get the relationship name from the backtrace.
     *
     * @return string
     */
    protected function guessBelongsToManyRelation()
    {
        if (method_exists(parent::class, 'guessBelongsToManyRelation')) {
            return parent::guessBelongsToManyRelation();
        }

        [$one, $two, $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    /**
     * Flush relationship cache and emit debug log.
     */
    protected function flushRelationshipCache(string $operation): void
    {
        if (method_exists($this, 'flushModelCache')) {
            $this->flushModelCache();
        } elseif (method_exists($this, 'flushCache')) {
            $this->flushCache();
        } else {
            throw new \Exception('The parent model must have a flushCache() or flushModelCache() method defined. Make sure your model uses the HasCachedQueries trait. The ModelRelationships trait should be used in conjunction with the HasCachedQueries trait. See the documentation for more information.');
        }

        resolve(ModelCacheDebugger::class)->info("Cache flushed after {$operation} operation for model: " . get_class($this));
    }
}
