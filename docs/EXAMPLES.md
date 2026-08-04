# Examples

Runnable patterns for common scenarios. The snippets mirror the package's test suite, so they stay accurate.

## Model with relationships (`belongsToMany`)

Use `HasCacheableModel` when pivot changes must invalidate the parent model's cache:

```php
use YMigVal\LaravelModelCache\HasCacheableModel;

class Post extends Model
{
    use HasCacheableModel;

    protected $cacheMinutes = 120;

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}

class Tag extends Model
{
    use HasCacheableModel;
}

// Pivot operations flush the parent model's cache automatically,
// gated on actual changes:
$post->tags()->attach([1, 2]);  // flushes Post cache
$post->tags()->sync([2, 3]);    // flushes Post cache (only when something changed)
$post->tags()->detach([1]);     // flushes Post cache

// Eager-loaded queries are cached together with the parent:
$posts = Post::with('tags')->where('published', true)->get(); // cached as one entry

// Note: pivot changes flush the parent (Post). The related model's
// own cached queries (e.g. Tag::all()) are not flushed by them.
```

## Pagination with a custom total

```php
// Plain paginate() is cached as a single key (count + items together):
$page = Post::paginate(15);

// Explicit variant with a pre-computed total (changes the cache key):
$total = Post::where('published', true)->count(); // cached count
$page = Post::where('published', true)
    ->paginateFromCache(15, ['*'], 'page', 2, $total);
```

## Aggregates

```php
$count = Post::where('published', true)->count(); // cached int
$views = Post::sum('views');
$average = Post::avg('views');

// Explicit variants:
$count = Post::where('published', true)->countFromCache();
```

## Transactions

Flushes are deferred until the commit — a rollback never touches the cache:

```php
DB::transaction(function () {
    $post = Post::create(['title' => 'New Post', 'content' => 'Content']);
    $post->update(['title' => 'Updated']);

    // Nothing flushed yet. If an exception is thrown here,
    // the cache is left untouched.
});

// The cache is flushed once, after the commit.
```

## Per-model configuration and bypassing the cache

```php
class Post extends Model
{
    use HasCachedQueries;

    protected $cacheMinutes = 60;     // TTL in minutes (default: config)
    protected $cachePrefix = 'posts_'; // key prefix (default: config)
    protected $cacheLockSeconds = 10;  // stampede lock (default: config)
}

class AuditLog extends Model
{
    use HasCachedQueries;

    protected $cacheMinutes = 0; // never cache this model
}

// Per-query control:
$fresh = Post::withoutCache()->get();       // bypass the cache once
$hot = Post::remember(5)->get();            // custom TTL for one query
Post::where('published', true)->flushCache(); // flush one query's key + tags
Post::flushModelCache();                    // flush the whole model's cache
```
