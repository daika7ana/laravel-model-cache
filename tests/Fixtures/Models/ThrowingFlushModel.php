<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose static flush method throws, forcing the mcache:flush command
 * into its error path (used to test the full-cache-flush confirmation).
 */
class ThrowingFlushModel extends Model
{
    public static function flushModelCache(): bool
    {
        throw new \RuntimeException('Simulated flush failure');
    }
}
