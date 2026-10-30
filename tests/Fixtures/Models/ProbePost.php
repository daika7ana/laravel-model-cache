<?php

namespace Daika7ana\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Daika7ana\LaravelModelCache\HasCachedQueries;

class ProbePost extends Model
{
    use HasCachedQueries;

    protected $table = 'posts';

    protected $fillable = [
        'title',
        'content',
        'published',
    ];

    protected $casts = [
        'published' => 'boolean',
    ];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }

    public function newEloquentBuilder($query)
    {
        return new ProbeCacheableBuilder(
            $query,
            $this->cacheMinutes ?? null,
            $this->cachePrefix ?? null,
            $this->cacheLockSeconds ?? null,
        );
    }
}
