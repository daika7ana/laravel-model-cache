<?php

namespace Daika7ana\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Daika7ana\LaravelModelCache\HasCachedQueries;

class ZeroCacheAuthor extends Model
{
    use HasCachedQueries;

    public $cacheMinutes = 0;

    protected $table = 'authors';

    protected $fillable = [
        'name',
        'email',
    ];
}
