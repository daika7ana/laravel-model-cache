<?php

namespace Daika7ana\LaravelModelCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the cache store every read and flush path uses.
 *
 * Reads and flushes must agree: when the configured model cache store is
 * unusable, both fall back to the default store, so an invalidation never
 * lands in a store the reads never wrote to.
 */
final class CacheStoreResolver
{
    public static function resolve(): Repository
    {
        try {
            $store = config('model-cache.cache_store');

            return $store ? Cache::store($store) : Cache::store();
        } catch (\Throwable $e) {
            resolve(ModelCacheDebugger::class)->warning("Configured model cache store is unavailable ({$e->getMessage()}); falling back to the default cache store.");

            return Cache::store(config('cache.default'));
        }
    }

    /**
     * The name of the store resolve() serves, fallback included, so status
     * output can name the store reads and flushes actually use.
     */
    public static function resolveStoreName(): string
    {
        $store = (string) config('model-cache.cache_store');

        try {
            $store ? Cache::store($store) : Cache::store();
        } catch (\Throwable $e) {
            return (string) config('cache.default');
        }

        return $store ?: (string) config('cache.default');
    }
}
