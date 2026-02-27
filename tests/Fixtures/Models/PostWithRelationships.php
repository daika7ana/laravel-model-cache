<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use YMigVal\LaravelModelCache\HasCachableModel;

class PostWithRelationships extends Model
{
    use HasCachableModel, SoftDeletes;

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
     * Define an inverse one-to-many relationship with author.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /**
     * Define a one-to-many relationship with comments.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }

    /**
     * Define a polymorphic one-to-many relationship with comments.
     */
    public function polymorphicComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Define a has-one-of-many relationship for the latest comment.
     */
    public function latestComment(): HasOne
    {
        return $this->comments()->one()->latestOfMany();
    }

    /**
     * Define a direct has-one-of-many relationship using latestOfMany.
     */
    public function latestCommentDirect(): HasOne
    {
        return $this->hasOne(Comment::class, 'post_id', 'id')->latestOfMany();
    }

    /**
     * Define a has-one-of-many relationship by latest updated timestamp.
     */
    public function mostRecentlyUpdatedComment(): HasOne
    {
        return $this->hasOne(Comment::class, 'post_id', 'id')->ofMany('updated_at', 'max');
    }

    /**
     * Define a many-to-many relationship with tags.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}
