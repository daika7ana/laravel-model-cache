<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use YMigVal\LaravelModelCache\HasCachedQueries;

class Author extends Model
{
    use HasCachedQueries;

    protected $fillable = [
        'name',
        'email',
    ];

    /**
     * Define a one-to-many relationship with posts.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Define a has-many-through relationship with comments through posts.
     */
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(Comment::class, Post::class, 'author_id', 'post_id', 'id', 'id');
    }

    /**
     * Define a has-one-through relationship with comments through posts.
     */
    public function firstComment(): HasOneThrough
    {
        return $this->hasOneThrough(Comment::class, Post::class, 'author_id', 'post_id', 'id', 'id');
    }
}
