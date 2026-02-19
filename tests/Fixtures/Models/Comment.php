<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use YMigVal\LaravelModelCache\HasCachedQueries;

class Comment extends Model
{
    use HasCachedQueries;

    protected $fillable = [
        'post_id',
        'author',
        'body',
        'commentable_id',
        'commentable_type',
    ];

    /**
     * Define an inverse one-to-many relationship with post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(PostWithRelationships::class, 'post_id');
    }

    /**
     * Define the polymorphic parent relationship.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
