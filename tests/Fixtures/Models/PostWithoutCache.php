<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PostWithoutCache - A fixture model for testing cache behavior.
 *
 * This model uses the same 'posts' table as the Post model but does NOT include
 * the HasCachedQueries trait. It's used in tests to modify data without triggering
 * cache invalidation, allowing us to verify that:
 * 1. Caching is actually working (stale data is returned)
 * 2. Cache invalidation is working (fresh data after cached model operations)
 *
 * @see Post - The cached version of this model
 * @see CacheInvalidationTest - Tests that use both models to validate cache behavior
 */
class PostWithoutCache extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     * Must match the Post model's table.
     */
    protected $table = 'posts';

    protected $fillable = [
        'title',
        'content',
        'published',
        'views',
    ];

    protected $casts = [
        'published' => 'boolean',
        'views' => 'integer',
    ];

    /**
     * Define a many-to-many relationship with tags.
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Scope for published posts.
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    /**
     * Scope for popular posts.
     */
    public function scopePopular($query)
    {
        return $query->where('views', '>', 1000);
    }
}
