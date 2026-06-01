<?php

namespace YMigVal\LaravelModelCache\Contracts;

interface HasCachedQueriesContract
{
    /**
     * Static method to flush cache for the model.
     */
    public static function flushModelCache(): bool;

    /**
     * Flush the cache for this model instance.
     */
    public function flushCache(): bool;
}
