<?php

namespace Daika7ana\LaravelModelCache;

/**
 * Helper trait to flush cache when relationship methods are called.
 *
 * Add it alongside HasCachedQueries: every belongsToMany() relation is then a
 * CachingBelongsToMany and every morphToMany()/morphedByMany() a
 * CachingMorphToMany; both flush the parent's cache after attach/detach/sync.
 * The *AndFlushCache() helpers below are legacy sugar for the same thing.
 */
trait HasCachedRelationships
{
    /**
     * Sync a belongsToMany relation and flush the parent's cache (via the relation).
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

        return $this->$relation()->sync($ids, $detaching);
    }

    /**
     * Attach to a belongsToMany relation and flush the parent's cache (via the relation).
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
    }

    /**
     * Detach from a belongsToMany relation and flush the parent's cache (via the relation).
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

        return $this->$relation()->detach($ids, $touch);
    }

    /**
     * Override the morphToMany factory so every polymorphic many-to-many
     * relation (including morphedByMany, which delegates here) is a
     * CachingMorphToMany that flushes the cache after attach/detach/sync.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $name
     * @param  string  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string|null  $relationName
     * @param  bool  $inverse
     * @return \Daika7ana\LaravelModelCache\CachingMorphToMany
     */
    protected function newMorphToMany(
        $query,
        $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false,
    ) {
        return new CachingMorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse,
        );
    }

    /**
     * Override the belongsToMany factory so every many-to-many relation is a
     * CachingBelongsToMany that flushes the cache after attach/detach/sync.
     *
     * The framework's belongsToMany() keeps its own logic (joiningTable with the
     * related instance, relation-name guessing, …) and calls this factory.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string|null  $relationName
     * @return \Daika7ana\LaravelModelCache\CachingBelongsToMany
     */
    protected function newBelongsToMany(
        $query,
        $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ) {
        return new CachingBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
        );
    }
}
