# How to Install and Configure

## 1) Install the package

```bash
composer require ymigval/laravel-model-cache
```

## 2) (Optional) Publish the config

```bash
php artisan vendor:publish --provider="YMigVal\LaravelModelCache\ModelCacheServiceProvider" --tag="config"
```

This creates `config/model-cache.php`.

## 3) Choose a cache driver

Tag-based invalidation requires a **tag-capable** driver: Redis or Memcached. With drivers that don't support tags (file, database), writes log a warning and **skip the flush** instead of wiping unrelated application cache (sessions, auth, ...).

```env
# Redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis

# or Memcached
CACHE_STORE=memcached
MEMCACHED_HOST=127.0.0.1
```

> Reads are cached with any driver — only selective invalidation needs tags.

## 4) Configuration reference

| Key | Env var | Default | Purpose |
|-----|---------|---------|---------|
| `enabled` | `MODEL_CACHE_ENABLED` | `true` | Global on/off switch |
| `cache_duration` | — | `60` | Default TTL in minutes |
| `cache_key_prefix` | — | `model_cache_` | Cache key prefix |
| `cache_store` | `MODEL_CACHE_STORE` | app default | Store used for model cache |
| `hash_algorithm` | `MODEL_CACHE_HASH_ALGORITHM` | `xxh128` | Key hashing algorithm (validated at boot) |
| `include_locale_in_key` | `MODEL_CACHE_INCLUDE_LOCALE` | `false` | Add the app locale to cache keys |
| `debug_mode` | `MODEL_CACHE_DEBUG` | `false` | Log key generation and flush operations |
| `use_cache_locks` | `MODEL_CACHE_USE_LOCKS` | `false` | Stampede prevention |
| `cache_lock_seconds` | `MODEL_CACHE_LOCK_SECONDS` | `5` | Stampede lock duration (seconds) |

> Errors and warnings are always logged; only `debug`/`info` messages are gated behind `debug_mode`.
