<?php

namespace YMigVal\LaravelModelCache\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface CacheableBuilderContract
{
    /**
     * Execute the query and get the results from the cache.
     */
    public function getFromCache($columns = ['*']): Collection;

    /**
     * Execute the query and get the first result from the cache.
     */
    public function firstFromCache($columns = ['*']);

    /**
     * Set the cache duration in minutes.
     */
    public function remember(int $minutes);

    /**
     * Disable caching for this query.
     */
    public function withoutCache();

    /**
     * Get a unique cache key for the complete query.
     */
    public function getCacheKey($columns = ['*']): string;

    /**
     * Flush the cache for this specific query.
     */
    public function flushQueryCache($columns = ['*']): bool;

    /**
     * Retrieve the "count" result of the query from cache.
     */
    public function countFromCache($columns = '*'): int;

    /**
     * Retrieve the sum of the values of a given column from cache.
     */
    public function sumFromCache(string $column);

    /**
     * Retrieve the maximum value of a given column from cache.
     */
    public function maxFromCache(string $column);

    /**
     * Retrieve the minimum value of a given column from cache.
     */
    public function minFromCache(string $column);

    /**
     * Retrieve the average of the values of a given column from cache.
     */
    public function avgFromCache(string $column);
}
