# Laravel Model Cache

Cache Eloquent queries with minimal code changes and automatic invalidation.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ymigval/laravel-model-cache.svg?style=flat-square)](https://packagist.org/packages/ymigval/laravel-model-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/ymigval/laravel-model-cache.svg?style=flat-square)](https://packagist.org/packages/ymigval/laravel-model-cache)
[![License](https://img.shields.io/packagist/l/ymigval/laravel-model-cache.svg?style=flat-square)](LICENSE.md)

> This repository is a fork of the original project by Yordan:
> https://github.com/ymigval/laravel-model-cache

## What this package does

- Replaces Eloquent's default builder with a cache-aware builder.
- Caches regular query results (`get()`, `first()`, etc.) automatically.
- Supports explicit cache methods (`getFromCache()`, `firstFromCache()`) when you want more explicit code.
- Flushes cache on create/update/delete/restore model events.
- Supports relationship-aware invalidation via `HasCachedRelationships`.
- **Fail-open** — if the cache driver is unavailable, queries fall through to the database instead of crashing.
- **Transaction-aware** — cache invalidation is deferred until the transaction commits, avoiding unnecessary flushes on rollback.
- **Stampede prevention** — optional cache locking prevents multiple concurrent requests from hitting the database simultaneously on cache miss.
- **Per-model configuration** — configure cache duration, prefix, and lock duration per model.

## Requirements

- PHP `^8.2`
- Laravel `11.x` through `13.x`

## Quick Start

### 1) Install

```bash
composer require ymigval/laravel-model-cache
```

### 2) Add `HasCachedQueries` to a model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YMigVal\LaravelModelCache\HasCachedQueries;

class Post extends Model
{
    use HasCachedQueries;

    protected $cacheMinutes = 120;
    protected $cachePrefix = 'posts_';
    protected $cacheLockSeconds = 15; // optional: override stampede lock duration
}
```

> **Tip:** If your model also needs relationship cache invalidation, you can use the convenience trait `HasCacheableModel` instead, which includes both `HasCachedQueries` and `HasCachedRelationships`.

### 3) Query as usual

```php
$posts = Post::where('published', true)->get();
$post = Post::whereKey(1)->first();

$featured = Post::where('featured', true)->remember(30)->get();
```

### Optional explicit query methods

```php
$posts = Post::where('published', true)->getFromCache();
$post = Post::whereKey(1)->firstFromCache();
```

## Common Command

```bash
php artisan mcache:flush
php artisan mcache:flush "App\\Models\\Post"
```

## Documentation

Detailed HOW TO guides are in [docs/README.md](docs/README.md):

- [How to install and configure](docs/HOW-TO-INSTALL.md)
- [How to use in models and queries](docs/HOW-TO-USAGE.md)
- [How to clear cache](docs/HOW-TO-CACHE-FLUSHING.md)
- [How to troubleshoot common issues](docs/HOW-TO-TROUBLESHOOTING.md)

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
