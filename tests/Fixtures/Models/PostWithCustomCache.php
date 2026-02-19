<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use YMigVal\LaravelModelCache\HasCachedQueries;

class PostWithCustomCache extends Model
{
    use HasCachedQueries;

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
     * Custom cache duration for this model (2 hours).
     */
    protected $cacheMinutes = 120;

    /**
     * Custom cache prefix for this model.
     */
    protected $cachePrefix = 'custom_post_';
}
