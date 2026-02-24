<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use YMigVal\LaravelModelCache\HasCachedQueries;

class PostWithEagerLoading extends Model
{
    use HasCachedQueries, SoftDeletes;

    protected $table = 'posts';

    protected $fillable = [
        'title',
        'content',
        'published',
        'views',
        'author_id',
    ];

    protected $casts = [
        'published' => 'boolean',
        'views' => 'integer',
    ];

    /**
     * The relations to eager load by default.
     *
     * @var array
     */
    protected $with = ['tags'];

    /**
     * Define an inverse one-to-many relationship with author.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /**
     * Define a many-to-many relationship with tags.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
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
