<?php

namespace Daika7ana\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Daika7ana\LaravelModelCache\HasCachedQueries;

class ShortCacheAuthor extends Model
{
    use HasCachedQueries;

    public $cacheMinutes = 1;

    protected $table = 'authors';

    protected $fillable = [
        'name',
        'email',
    ];
}
