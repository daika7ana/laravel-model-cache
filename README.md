# Laravel Model Cache

Cache Eloquent queries with minimal code changes and automatic invalidation.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ymigval/laravel-model-cache.svg?style=flat-square)](https://packagist.org/packages/ymigval/laravel-model-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/ymigval/laravel-model-cache.svg?style=flat-square)](https://packagist.org/packages/ymigval/laravel-model-cache)
[![License](https://img.shields.io/packagist/l/ymigval/laravel-model-cache.svg?style=flat-square)](LICENSE.md)

> This repository is a fork of the original project by Yordan:
> https://github.com/ymigval/laravel-model-cache

## Features

- **Automatic caching** of `get()`, `first()`, `count()`, other aggregates, and `paginate()` via a drop-in builder.
- **Tag-based invalidation** on create/update/delete/restore and all mass operations.
- **Transaction-aware** — flushes are deferred until commit, never on rollback.
- **Fail-open** — cache errors fall through to the database instead of crashing.
- **Stampede prevention** — optional cache locking for concurrent cache misses.
- **Per-model configuration** — cache duration, key prefix, and lock duration per model.

## Requirements

- PHP `^8.2`
- Laravel `11.x` through `13.x`
- Redis or Memcached for tag-based invalidation (recommended)

## Quick Start

### 1) Install

```bash
composer require ymigval/laravel-model-cache
```

### 2) Add the trait to a model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use YMigVal\LaravelModelCache\HasCachedQueries;

class Post extends Model
{
    use HasCachedQueries;

    protected $cacheMinutes = 120;     // optional: TTL in minutes (default: config)
    protected $cachePrefix = 'posts_'; // optional: key prefix (default: config)
    protected $cacheLockSeconds = 15;  // optional: stampede lock duration (default: config)
}
```

> **Tip:** Use `HasCacheableModel` instead to also invalidate the cache on `belongsToMany` attach/detach/sync.

### 3) Query as usual

```php
$posts = Post::where('published', true)->get();                 // cached automatically
$featured = Post::where('featured', true)->remember(30)->get(); // per-query TTL
$fresh = Post::where('featured', true)->withoutCache()->get();  // bypass cache
```

Explicit variants (`getFromCache()`, `firstFromCache()`, `paginateFromCache()`) are also available.

## Clearing the cache

```bash
php artisan mcache:flush                     # all cached models
php artisan mcache:flush "App\\Models\\Post" # one model
```

## Documentation

How-to guides — start at [docs/README.md](docs/README.md):

- [How to install and configure](docs/HOW-TO-INSTALL.md)
- [How to use in models and queries](docs/HOW-TO-USAGE.md)
- [Examples](docs/EXAMPLES.md)
- [API reference](docs/API-REFERENCE.md)
- [How to clear cache](docs/HOW-TO-CACHE-FLUSHING.md)
- [How to troubleshoot common issues](docs/HOW-TO-TROUBLESHOOTING.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
