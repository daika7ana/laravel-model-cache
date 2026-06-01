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
