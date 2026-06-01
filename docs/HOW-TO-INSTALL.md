# How To Install and Configure

## 1) Install the package

```bash
composer require ymigval/laravel-model-cache
```

## 2) (Optional) Publish config

```bash
php artisan vendor:publish --provider="YMigVal\LaravelModelCache\ModelCacheServiceProvider" --tag="config"
```

This creates `config/model-cache.php`.

## 3) Configure a cache driver

Tag-aware drivers are recommended for best selective invalidation.

### Redis (recommended)

```env
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Memcached

```env
CACHE_STORE=memcached
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211
```

### Database

```bash
php artisan cache:table
php artisan migrate
```

```env
CACHE_STORE=database
```

### File / Array

`file` and `array` drivers do not support tags. Without tags, cache invalidation is skipped to avoid flushing unrelated application cache (sessions, auth, etc.). Use Redis or Memcached for full invalidation support.

## 4) Optional package settings

In `config/model-cache.php` you can tune:

- `enabled` — globally enable/disable query caching (default: `true`)
- `cache_duration` — default TTL in minutes (default: `60`)
- `cache_key_prefix` — prefix for all cache keys (default: `model_cache_`)
- `cache_store` — cache store to use, `null` uses the app default
- `hash_algorithm` — algorithm for cache key hashing (default: `xxh128`)
- `include_locale_in_key` — include app locale in cache key for multilingual sites (default: `false`)
- `debug_mode` — log cache key generation and flush operations (default: `false`)
- `use_cache_locks` — enable stampede prevention locking (default: `false`)
- `cache_lock_seconds` — lock duration in seconds for stampede prevention (default: `10`)

> **Note:** Errors and warnings are always logged regardless of `debug_mode`. Only `debug` and `info` level messages are gated behind the debug flag.

Example:

```php
'cache_store' => env('MODEL_CACHE_STORE', null),
'include_locale_in_key' => env('MODEL_CACHE_INCLUDE_LOCALE', false),
'debug_mode' => env('MODEL_CACHE_DEBUG', false),
'use_cache_locks' => env('MODEL_CACHE_USE_LOCKS', false),
'cache_lock_seconds' => (int) env('MODEL_CACHE_LOCK_SECONDS', 10),
```
