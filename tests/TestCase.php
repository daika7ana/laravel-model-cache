<?php

namespace YMigVal\LaravelModelCache\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use YMigVal\LaravelModelCache\ModelCacheServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/database/migrations');

        if ($this->usesDatabaseCacheDriver()) {
            $this->ensureDatabaseCacheTables();
        }
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            ModelCacheServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup cache configuration
        $cacheDriver = env('CACHE_DRIVER', 'array');

        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);

        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DB', 0),
            'prefix' => env('REDIS_PREFIX', ''),
        ]);

        $app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);

        $app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'table' => env('CACHE_TABLE', 'cache'),
            'connection' => env('CACHE_DATABASE_CONNECTION', 'testing'),
            'lock_connection' => env('CACHE_LOCK_CONNECTION', 'testing'),
            'lock_table' => env('CACHE_LOCK_TABLE', 'cache_locks'),
        ]);

        $app['config']->set('cache.default', $cacheDriver);

        // Setup model-cache configuration
        $app['config']->set('model-cache.cache_duration', 60);
        $app['config']->set('model-cache.cache_key_prefix', 'test_cache_');
        $app['config']->set('model-cache.cache_store', env('MODEL_CACHE_STORE', null));
        $app['config']->set('model-cache.enabled', true);
        $app['config']->set('model-cache.debug_mode', false);
    }

    protected function usesDatabaseCacheDriver(): bool
    {
        $cacheDriver = config('cache.default');
        $modelCacheStore = config('model-cache.cache_store');

        return $cacheDriver === 'database' || $modelCacheStore === 'database';
    }

    protected function ensureDatabaseCacheTables(): void
    {
        $cacheTable = config('cache.stores.database.table', 'cache');
        $cacheLockTable = config('cache.stores.database.lock_table', 'cache_locks');

        if (! Schema::hasTable($cacheTable)) {
            Schema::create($cacheTable, function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable($cacheLockTable)) {
            Schema::create($cacheLockTable, function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }
}
