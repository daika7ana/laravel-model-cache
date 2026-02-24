<?php

namespace YMigVal\LaravelModelCache\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithoutCache;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Tag;
use YMigVal\LaravelModelCache\Tests\TestCase;

class ConsoleCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    #[Test]
    public function it_can_flush_cache_for_specific_model()
    {
        // Create and cache some data
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Cache the query
        Post::where('published', true)->get();

        // Run the command
        $this->artisan('mcache:flush', [
            'model' => Post::class,
        ])
            ->expectsOutputToContain('Attempting to clear cache for model: ' . Post::class)
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_flush_cache_for_all_models()
    {
        // Create and cache data for multiple models
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        Tag::create(['name' => 'Test Tag']);

        // Cache queries
        Post::where('published', true)->get();
        Tag::all();

        // Run the command without model argument
        $this->artisan('mcache:flush')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_shows_error_for_non_existent_model()
    {
        // Run the command with non-existent model
        $this->artisan('mcache:flush', [
            'model' => 'App\\Models\\NonExistentModel',
        ])
            ->expectsOutputToContain('Model class App\\Models\\NonExistentModel does not exist!')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_warns_when_model_does_not_use_trait()
    {
        $modelClass = PostWithoutCache::class;

        $this->artisan('mcache:flush', [
            'model' => $modelClass,
        ])
            ->expectsOutputToContain("Warning: The model {$modelClass} doesn't use HasCachedQueries trait. Cache functionality might be limited.")
            ->assertExitCode(0);
    }

    #[Test]
    public function it_displays_cache_configuration()
    {
        $cacheDriver = config('cache.default', 'array');

        // Run the command
        $this->artisan('mcache:flush', [
            'model' => Post::class,
        ])
            ->expectsOutputToContain("Current cache driver: {$cacheDriver}")
            ->assertExitCode(0);
    }
}
