<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use YMigVal\LaravelModelCache\HasCachedQueries;

class Post extends Model
{
    use HasCachedQueries, SoftDeletes;

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
