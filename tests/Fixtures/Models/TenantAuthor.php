<?php

namespace Daika7ana\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Daika7ana\LaravelModelCache\HasCachedQueries;

class TenantAuthor extends Model
{
    use HasCachedQueries;

    protected $table = 'authors';

    protected $fillable = [
        'name',
        'email',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(TenantPost::class, 'author_id');
    }

    public function filteredPosts(): HasMany
    {
        return $this->hasMany(TenantPost::class, 'author_id')
            ->when(TenantPost::$publishedFilter !== null, fn($query) => $query->where('published', TenantPost::$publishedFilter));
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (TenantState::$tenant !== null) {
                $builder->where('name', TenantState::$tenant);
            }
        });
    }
}
