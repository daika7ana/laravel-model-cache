<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use YMigVal\LaravelModelCache\HasCachedQueries;

class Tag extends Model
{
    use HasCachedQueries;

    protected $fillable = [
        'name',
    ];

    /**
     * Define a many-to-many relationship with posts.
     */
    public function posts()
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * Define a polymorphic one-to-many relationship with comments.
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
