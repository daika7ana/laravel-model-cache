<?php

namespace YMigVal\LaravelModelCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
            $this->clearModelCache($modelClass);
        } else {
            $this->clearAllModelCache();
        }

        return CommandAlias::SUCCESS;
    }

    /**
     * Clear cache for a specific model.
     */
    protected function clearModelCache(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            $this->components->error("Model class {$modelClass} does not exist!");

            return;
        }

        if (! is_a($modelClass, Model::class, true)) {
            $this->components->error("Class {$modelClass} is not an Eloquent model.");

            return;
        }

        $this->components->info("Attempting to clear cache for model: {$modelClass}");

        // Check if the model uses our trait
        if (! $this->usesHasCachedQueriesTrait($modelClass)) {
            $this->components->warn("Warning: The model {$modelClass} doesn't use HasCachedQueries trait. Cache functionality might be limited.");
        }

        // Show the current cache configuration
        $this->components->info('Current cache driver: ' . config('cache.default'));
        $this->components->info('Model cache store: ' . config('model-cache.cache_store', 'default'));

        try {
            // First check if the model has static flush methods
            if (
                method_exists($modelClass, 'flushModelCache')
                && (new ReflectionMethod($modelClass, 'flushModelCache'))->isStatic()
            ) {
                $result = $modelClass::flushModelCache();
                if ($result) {
                    $this->components->info("Cache cleared successfully for model: {$modelClass}");
                } else {
                    $this->components->warn('Static method returned false - cache may not have been cleared completely');
                    // Force a full cache clear as a backup
                    $this->performFullCacheFlush();
                }

                return;
            }

            $this->components->info('No static methods found. Trying with instance methods...');

            // If no static methods, try instance methods
            $model = new $modelClass;
            $tableName = $model->getTable();
            $this->components->info("Model table: {$tableName}");

            if (method_exists($model, 'flushCache')) {
                $result = $model->flushCache();
                if ($result) {
                    $this->components->info("Cache cleared successfully for model: {$modelClass}");
                } else {
                    $this->components->warn('Instance method returned false - cache may not have been cleared completely');
                    // Force a full cache clear as a backup
                    $this->performFullCacheFlush();
                }
            } elseif (method_exists($model, 'flushModelCache')) {
                // For backward compatibility with instance methods
                $result = $model->flushModelCache();
                if ($result) {
                    $this->components->info("Cache cleared successfully for model: {$modelClass}");
                } else {
                    $this->components->warn('Instance method returned false - cache may not have been cleared completely');
                    // Force a full cache clear as a backup
                    $this->performFullCacheFlush();
                }
            } else {
                $this->components->warn('No cache flush methods found on the model. Using manual clearing...');
                $this->clearModelCacheManually($modelClass, $tableName);
            }
        } catch (\Exception $e) {
            $this->components->error("Error clearing cache for {$modelClass}: " . $e->getMessage());
            $this->components->error('Stack trace: ' . $e->getTraceAsString());

            // Ask if user wants to try full cache flush as a last resort
            if ($this->components->confirm('Would you like to clear the entire application cache?', true)) {
                $this->performFullCacheFlush();
            }
        }
    }

    /**
     * Check if a model uses the HasCachedQueries trait.
     */
    protected function usesHasCachedQueriesTrait(string $class): bool
    {
        $traits = class_uses_recursive($class);

        return isset($traits['YMigVal\LaravelModelCache\HasCachedQueries']);
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
        } catch (\Exception $e) {
            $this->components->error('Error performing full cache flush: ' . $e->getMessage());
        }
    }

    /**
     * Clear cache manually when flushModelCache is not available.
     */
    protected function clearModelCacheManually(string $modelClass, string $tableName): void
    {
        try {
            $cache = $this->cacheRepository();

            $tags = ['model_cache', $modelClass, $tableName];

            // First try to use tags if supported
            if ($this->supportsTags($cache)) {
                try {
                    $cache->tags($tags)->flush();
                    $this->components->info("Cache cleared for model: {$modelClass} using tags");

                    return;
                } catch (\Exception $e) {
                    $this->components->warn('Error using cache tags: ' . $e->getMessage());
                }
            }

            // If we reach here, tags are not supported or failed
            // For simplicity, just confirm and clear all cache
            if ($this->components->confirm("Your cache driver doesn't support tags or there was an error. Would you like to clear ALL application cache?", false)) {
                $cache->flush();
                $this->components->info('All cache cleared successfully');
            } else {
                $this->components->info('Cache clearing cancelled');
            }

        } catch (\Exception $e) {
            $this->components->error('Error clearing cache: ' . $e->getMessage());
        }
    }

    /**
     * Clear cache for all models.
     */
    protected function clearAllModelCache(): void
    {
        try {
            $cache = $this->cacheRepository();

            // First try to use tags if supported
            if ($this->supportsTags($cache)) {
                try {
                    $cache->tags('model_cache')->flush();
                    $this->components->success('Cache cleared for all models using tags');

                    return;
                } catch (\Exception $e) {
                    $this->components->warn('Error using cache tags: ' . $e->getMessage());
                }
            }

            // If we reach here, tags are not supported or failed
            // Ask for confirmation before clearing all cache
            if ($this->components->confirm('Your cache driver doesn\'t support tags. This will clear ALL application cache. Continue?', false)) {
                $cache->flush();
                $this->components->success('All cache cleared successfully');
            } else {
                $this->components->info('Cache clearing cancelled');
            }
        } catch (\Exception $e) {
            $this->components->error('Error clearing cache: ' . $e->getMessage());
        }
    }

    /**
     * Check if the cache repository supports tagging.
     */
    protected function supportsTags(Repository $cache): bool
    {
        try {
            return method_exists($cache, 'supportsTags') && $cache->supportsTags();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Resolve cache repository using configured model cache store.
     */
    protected function cacheRepository(): Repository
    {
        $cacheStore = config('model-cache.cache_store');

        return $cacheStore ? Cache::store($cacheStore) : Cache::store();
    }
}
