# How to Install and Configure

## 1) Install the package

```bash
composer require daika7ana/laravel-model-cache
```

## 2) (Optional) Publish the config

```bash
php artisan vendor:publish --provider="Daika7ana\LaravelModelCache\ModelCacheServiceProvider" --tag="config"
```

This creates `config/model-cache.php`.

## 3) Choose a cache driver

Caching and tag-based invalidation require a **tag-capable** driver: Redis or Memcached (the array store is tag-capable and used in tests). On drivers without tags (file, database), reads bypass the cache entirely — nothing is cached that could not be invalidated — and flushes are **skipped** instead of wiping unrelated application cache (sessions, auth, ...), with one warning per model class.

```env
# Redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis

# or Memcached
CACHE_STORE=memcached
MEMCACHED_HOST=127.0.0.1
```

## 4) Configuration reference

| Key | Env var | Default | Purpose |
|-----|---------|---------|---------|
| `enabled` | `MODEL_CACHE_ENABLED` | `true` | Global on/off switch |
| `cache_duration` | — | `60` | Default TTL in minutes |
| `cache_key_prefix` | — | `model_cache_` | Cache key prefix |
| `cache_store` | `MODEL_CACHE_STORE` | app default | Store used for model cache |
| `hash_algorithm` | `MODEL_CACHE_HASH_ALGORITHM` | `xxh128` | Key hashing algorithm (validated at boot) |
| `include_locale_in_key` | `MODEL_CACHE_INCLUDE_LOCALE` | `false` | Add the app locale to cache keys |
| `debug_mode` | `MODEL_CACHE_DEBUG` | `false` | Log key generation, cache hits/misses, and flush operations |
| `use_cache_locks` | `MODEL_CACHE_USE_LOCKS` | `false` | Stampede prevention |
| `cache_lock_seconds` | `MODEL_CACHE_LOCK_SECONDS` | `5` | Stampede lock duration (seconds) |
| `lock_wait_seconds` | `MODEL_CACHE_LOCK_WAIT_SECONDS` | `0.25` | Max wait for a stampede lock holder before running the query |

> Flush errors and warnings are always logged; cache read/write failures (with `debug_mode` on) and `debug`/`info` messages are gated behind `debug_mode`.
