# How to Use in Models and Queries

## Add the trait

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

## Query as usual — caching is automatic

```php
$posts = Post::where('published', true)->get();
$post = Post::whereKey(1)->first();
$count = Post::where('published', true)->count();
$page = Post::paginate(10); // also cached
```

## Per-query control

```php
$posts = Post::where('status', 'active')->remember(30)->get(); // custom TTL (minutes)
$fresh = Post::withoutCache()->get();                          // bypass the cache
$posts = Post::where('published', true)->getFromCache();       // explicit cache read
$post = Post::whereKey(1)->firstFromCache();
```

## Relationship invalidation (belongsToMany)

Add `HasCachedRelationships` (or the combined `HasCacheableModel`). Pivot changes via `attach()`, `detach()`, `sync()`, `syncWithoutDetaching()`, and `updateExistingPivot()` flush the cache automatically:

```php
use YMigVal\LaravelModelCache\HasCacheableModel;

class Post extends Model
{
    use HasCacheableModel;
}

$post->tags()->attach([1, 2]);
$post->tags()->sync([2, 3]);
```

## Stampede prevention (optional)

With `MODEL_CACHE_USE_LOCKS=true` (or a per-model `$cacheLockSeconds`), a cache miss acquires a lock; concurrent requests wait briefly and reuse the freshly computed value instead of all hitting the database.

## Transaction-aware invalidation

While inside a transaction, flushes are deferred until commit — a rollback never flushes the cache:

```php
DB::transaction(function () {
    $post = Post::create(['title' => 'New Post', 'content' => 'Content']);

    // Nothing flushed yet — the flush waits for the commit.
    // Throwing here leaves the cache untouched.
});

// Flushed once, after the transaction commits.
```
