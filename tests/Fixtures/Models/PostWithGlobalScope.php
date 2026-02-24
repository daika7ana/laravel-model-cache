<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;

class PostWithGlobalScope extends Post
{
    protected $table = 'posts';

    protected static function booted(): void
    {
        static::addGlobalScope('published_only', function (Builder $builder) {
            $builder->where('published', true);
        });
    }
}
