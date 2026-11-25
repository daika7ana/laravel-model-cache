<?php

namespace Daika7ana\LaravelModelCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Daika7ana\LaravelModelCache\CacheStoreResolver;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ClearModelCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcache:flush {model? : The model class name (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear cache for specific model or all cached models';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if ($modelClass) {
            return $this->clearModelCache($modelClass) ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
        }

        return $this->clearAllModelCache() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }

    /**
     * Clear cache for a specific model.
     */
    protected function clearModelCache(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            $this->components->error("Model class {$modelClass} does not exist!");

            return false;
        }

        if (! is_a($modelClass, Model::class, true)) {
            $this->components->error("Class {$modelClass} is not an Eloquent model.");

            return false;
        }

        $this->components->info("Attempting to clear cache for model: {$modelClass}");

        // Check if the model uses our trait
        if (! $this->usesHasCachedQueriesTrait($modelClass)) {
            $this->components->warn("Warning: The model {$modelClass} doesn't use HasCachedQueries trait. Cache functionality might be limited.");
        }

        // Show the current cache configuration (the store the flush actually uses)
        $this->components->info('Current cache driver: ' . (config('model-cache.cache_store') ?: config('cache.default')));
        $this->components->info('Model cache store: ' . config('model-cache.cache_store', 'default'));

        try {
            // First check if the model has static flush methods
            if (
                method_exists($modelClass, 'flushModelCache')
                && (new ReflectionMethod($modelClass, 'flushModelCache'))->isStatic()
            ) {
                return $this->reportFlushResult($modelClass, $modelClass::flushModelCache());
            }

            $this->components->info('No static methods found. Trying with instance methods...');

            // If no static methods, try instance methods
            $model = new $modelClass();
            $tableName = $model->getTable();
            $this->components->info("Model table: {$tableName}");

            if (method_exists($model, 'flushCache')) {
                return $this->reportFlushResult($modelClass, $model->flushCache());
            }

            if (method_exists($model, 'flushModelCache')) {
                // For backward compatibility with instance methods
                return $this->reportFlushResult($modelClass, $model->flushModelCache());
            }

            $this->components->warn('No cache flush methods found on the model. Using manual clearing...');

            return $this->clearModelCacheManually($modelClass, $tableName);
        } catch (\Throwable $e) {
            $this->components->error("Error clearing cache for {$modelClass}: " . $e->getMessage());
            $this->components->error('Stack trace: ' . $e->getTraceAsString());

            // Ask if user wants to try full cache flush as a last resort.
            // Default to No: pressing Enter must not wipe the entire application cache.
            if ($this->components->confirm('Would you like to clear the entire application cache?', false)) {
                $this->performFullCacheFlush();
            }

            return false;
        }
    }

    /**
     * Report the result of a model flush and signal success/failure to the shell.
     */
    protected function reportFlushResult(string $modelClass, $result): bool
    {
        if ($result) {
            $this->components->info("Cache cleared successfully for model: {$modelClass}");

            return true;
        }

        if (! $this->storeSupportsTags()) {
            $this->components->warn('Cache flush was skipped: the configured store does not support tags. Use Redis or Memcached for selective invalidation.');

            return false;
        }

        $this->components->warn('Cache flush failed. See the error above for details.');

        return false;
    }

    /**
     * Check whether the store used by the flush supports tags. A failed tag
     * flush is not a missing-tags store, and must not be reported as one.
     */
    protected function storeSupportsTags(): bool
    {
        return $this->supportsTags($this->cacheRepository());
    }

    /**
     * Check if a model uses the HasCachedQueries trait.
     */
    protected function usesHasCachedQueriesTrait(string $class): bool
    {
        $traits = class_uses_recursive($class);

        return isset($traits['Daika7ana\LaravelModelCache\HasCachedQueries']);
    }

    /**
     * Perform a full cache flush as a last resort.
     */
    protected function performFullCacheFlush(): void
    {
        $this->components->info('Performing full cache flush as a fallback...');

        try {
            $cache = $this->cacheRepository();

            // Flush everything
            $cache->flush();
            $this->components->info('Full application cache has been cleared successfully');
        } catch (\Throwable $e) {
            $this->components->error('Error performing full cache flush: ' . $e->getMessage());
        }
    }

    /**
     * Clear cache manually when flushModelCache is not available.
     */
    protected function clearModelCacheManually(string $modelClass, string $tableName): bool
    {
        // Per-model flush must not include the shared 'model_cache' tag:
        // resetting it would invalidate every cached model.
        return $this->clearCacheByTags(
            [$modelClass, $tableName],
            "model: {$modelClass}",
            "Your cache driver doesn't support tags or there was an error. Would you like to clear ALL application cache?",
        );
    }

    /**
     * Clear cache for all models.
     */
    protected function clearAllModelCache(): bool
    {
        return $this->clearCacheByTags(
            'model_cache',
            'all models',
            'Your cache driver doesn\'t support tags. This will clear ALL application cache. Continue?',
        );
    }

    /**
     * Flush the given tag(s), or ask before falling back to a full cache wipe.
     */
    protected function clearCacheByTags(array|string $tags, string $label, string $confirmMessage): bool
    {
        try {
            $cache = $this->cacheRepository();

            if ($this->supportsTags($cache)) {
                try {
                    $cache->tags($tags)->flush();
                    $this->components->info("Cache cleared for {$label} using tags");

                    return true;
                } catch (\Throwable $e) {
                    $this->components->warn('Error using cache tags: ' . $e->getMessage());
                }
            }

            // Tags unavailable or failed: never wipe the whole cache without consent.
            if ($this->components->confirm($confirmMessage, false)) {
                $cache->flush();
                $this->components->info('All cache cleared successfully');

                return true;
            }

            $this->components->info('Cache clearing cancelled');

            return false;
        } catch (\Throwable $e) {
            $this->components->error('Error clearing cache: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Check if the cache repository supports tagging.
     */
    protected function supportsTags(Repository $cache): bool
    {
        try {
            return method_exists($cache, 'supportsTags') && $cache->supportsTags();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve cache repository using configured model cache store.
     */
    protected function cacheRepository(): Repository
    {
        return CacheStoreResolver::resolve();
    }
}
