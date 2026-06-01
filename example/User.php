<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use YMigVal\LaravelModelCache\HasCachedQueries;

class User extends Authenticatable
{
    use HasCachedQueries;
    use Notifiable;

    // Alternatively, if you also need relationship cache invalidation:
    // use \YMigVal\LaravelModelCache\HasCacheableModel, Notifiable;

    /**
     * Cache duration in minutes (optional, defaults to config value).
     */
    protected $cacheMinutes = 60;

    /**
     * Custom cache key prefix (optional, defaults to config value).
     */
    protected $cachePrefix = 'user_';

    /**
     * Stampede lock duration in seconds (optional, defaults to config value).
     */
    protected $cacheLockSeconds = 10;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Example of using the cached queries in a scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        // The query results will be cached for 30 minutes
        return $query->where('active', true)->remember(30);
    }
}
