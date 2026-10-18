<?php

namespace Daika7ana\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Daika7ana\LaravelModelCache\HasCachedQueries;

class ShortCachePost extends Model
{
    use HasCachedQueries;

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

    public function author(): BelongsTo
    {
        return $this->belongsTo(ShortCacheAuthor::class);
    }
}
