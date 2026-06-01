<?php

namespace YMigVal\LaravelModelCache;

use Illuminate\Support\ServiceProvider;
use YMigVal\LaravelModelCache\Console\Commands\ClearModelCacheCommand;

class ModelCacheServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/model-cache.php' => config_path('model-cache.php'),
        ], 'config');

        $this->validateConfiguration();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearModelCacheCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ModelCacheDebugger::class);

        $this->mergeConfigFrom(
            __DIR__ . '/../config/model-cache.php',
            'model-cache',
        );
    }

    /**
     * Validate package configuration values.
     */
    protected function validateConfiguration(): void
    {
        $algorithm = config('model-cache.hash_algorithm', 'xxh128');

        if (! in_array($algorithm, hash_algos(), true)) {
            throw new \InvalidArgumentException(
                "Invalid model-cache.hash_algorithm '{$algorithm}'. Available algorithms: " . implode(', ', array_slice(hash_algos(), 0, 10)) . '...',
            );
        }
    }
}
