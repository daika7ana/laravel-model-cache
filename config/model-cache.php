<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Duration
    |--------------------------------------------------------------------------
    |
    | This value determines the default number of minutes to cache query results.
    |
    */
    'cache_duration' => 60,

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used for all cache keys to avoid collisions.
    |
    */
    'cache_key_prefix' => 'model_cache_',

    /*
    |--------------------------------------------------------------------------
    | Hash Algorithm
    |--------------------------------------------------------------------------
    |
    | Algorithm used to hash cache identifiers.
    | Defaults to xxh128 for speed when available.
    |
    */
    'hash_algorithm' => (string) env('MODEL_CACHE_HASH_ALGORITHM', 'xxh128'),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the cache store that gets used for storing
    | and retrieving queries. Use env('MODEL_CACHE_STORE') to specify
    | a different store than your main application cache.
    |
    | Note: For tag support, use Redis or Memcached drivers.
    |
    */
    'cache_store' => (string) env('MODEL_CACHE_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Enable Query Caching
    |--------------------------------------------------------------------------
    |
    | This option provides an easy way to globally enable/disable query caching.
    |
    */
    'enabled' => (bool) env('MODEL_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Include Locale in Cache Key
    |--------------------------------------------------------------------------
    |
    | When enabled, the application locale will be included in the cache key.
    | This is useful for multilingual sites where query results may differ
    | by locale. Enable this only if you need per-locale caching.
    |
    */
    'include_locale_in_key' => (bool) env('MODEL_CACHE_INCLUDE_LOCALE', false),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, this will log detailed information about cache keys and
    | queries being cached. Useful for troubleshooting cache-related issues.
    | Note: Errors and warnings are always logged regardless of this setting.
    |
    */
    'debug_mode' => (bool) env('MODEL_CACHE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Use Cache Locks (Stampede Prevention)
    |--------------------------------------------------------------------------
    |
    | When enabled, cache misses will acquire a lock before executing the
    | database query. This prevents multiple concurrent requests from all
    | hitting the database simultaneously when a cache key expires.
    | Requires a lock-capable cache driver (Redis, Memcached, database).
    |
    */
    'use_cache_locks' => (bool) env('MODEL_CACHE_USE_LOCKS', false),

    /*
    |--------------------------------------------------------------------------
    | Cache Lock Duration (seconds)
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds a cache stampede lock is held.
    | Should be longer than the slowest expected query.
    |
    */
    'cache_lock_seconds' => (int) env('MODEL_CACHE_LOCK_SECONDS', 5),
];
