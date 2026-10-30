<?php

namespace Daika7ana\LaravelModelCache\Tests\Fixtures\Models;

use Daika7ana\LaravelModelCache\CacheableBuilder;

class ProbeCacheableBuilder extends CacheableBuilder
{
    public static int $morphScans = 0;

    protected function modelHasUnmappedMorphType($model, \SplObjectStorage $visited): bool
    {
        self::$morphScans++;

        return parent::modelHasUnmappedMorphType($model, $visited);
    }
}
