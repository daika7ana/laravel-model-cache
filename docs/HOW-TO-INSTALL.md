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

`file` and `array` work with fallback behavior, but invalidation may flush broader cache scopes.

## 4) Optional package settings

In `config/model-cache.php` you can tune:

- `enabled`
- `cache_duration`
- `cache_key_prefix`
- `cache_store`

Example:

```php
'cache_store' => env('MODEL_CACHE_STORE', 'redis'),
```
