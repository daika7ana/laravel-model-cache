# How To Use in Models and Queries

## 1) Add `HasCachedQueries` to your model

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
}
```

### Per-model properties

| Property | Type | Description |
|----------|------|-------------|
| `$cacheMinutes` | `int\|null` | Cache duration in minutes. `null` uses `config('model-cache.cache_duration')`. |
| `$cachePrefix` | `string\|null` | Custom cache key prefix. `null` uses `config('model-cache.cache_key_prefix')`. |
| `$cacheLockSeconds` | `int\|null` | Stampede lock duration in seconds. `null` uses `config('model-cache.cache_lock_seconds')`. |

## 2) Use regular Eloquent methods (implicit caching)

```php
$posts = Post::where('published', true)->get();
$post = Post::whereKey(1)->first();
```

## 3) Use explicit caching methods (optional)

```php
$posts = Post::where('published', true)->getFromCache();
$post = Post::whereKey(1)->firstFromCache();
```

## 4) Set per-query cache duration

```php
$posts = Post::where('status', 'active')->remember(30)->get();
```

## 5) Skip cache when needed

```php
$freshPosts = Post::withoutCache()->get();
```

## 6) Use relationship cache invalidation helpers (belongsToMany)

Add both traits (or use the convenience trait):

```php
// Option A: individual traits
use YMigVal\LaravelModelCache\HasCachedQueries;
use YMigVal\LaravelModelCache\HasCachedRelationships;

class Post extends Model
{
    use HasCachedQueries, HasCachedRelationships;
}

// Option B: convenience trait (includes both)
use YMigVal\LaravelModelCache\HasCacheableModel;

class Post extends Model
{
    use HasCacheableModel;
}
```

Then use helper methods:

```php
$post->syncRelationshipAndFlushCache('tags', [1, 2, 3]);
$post->attachRelationshipAndFlushCache('tags', [4, 5]);
$post->detachRelationshipAndFlushCache('tags', [1]);
```

## 7) Stampede prevention (optional)

When a popular cache key expires, multiple concurrent requests can all miss and hit the database simultaneously. Enable cache locking to prevent this:

```env
MODEL_CACHE_USE_LOCKS=true
MODEL_CACHE_LOCK_SECONDS=10
```

Or set a per-model lock duration:

```php
class Post extends Model
{
    use HasCachedQueries;

    protected $cacheLockSeconds = 15; // override config for this model
}
```

## 8) Transaction-aware invalidation

Cache invalidation is automatically deferred when inside a database transaction. If the transaction rolls back, the cache is not flushed:

```php
DB::transaction(function () {
    $post = Post::create(['title' => 'New Post', 'content' => 'Content']);

    // Cache is NOT flushed yet — it waits for the transaction to commit
    $post->update(['title' => 'Updated Title']);

    // If an exception is thrown here, the cache remains valid
});

// Cache is flushed once, after the transaction commits successfully
```
