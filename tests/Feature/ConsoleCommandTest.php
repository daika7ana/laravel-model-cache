<?php

namespace YMigVal\LaravelModelCache\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use YMigVal\LaravelModelCache\Console\Commands\ClearModelCacheCommand;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithoutCache;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Tag;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\ThrowingFlushModel;
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

    #[Test]
    public function it_defaults_full_cache_flush_confirmation_to_no()
    {
        // A model whose flush throws forces the command into its error path,
        // where it asks whether to wipe the entire application cache.
        $output = Mockery::mock(OutputStyle::class . '[askQuestion]', [
            new ArrayInput([]),
            new BufferedOutput(),
        ]);

        $output->shouldReceive('askQuestion')
            ->once()
            ->with(Mockery::on(function ($question) {
                $this->assertFalse($question->getDefault(), 'Full cache flush confirm must default to No');

                return true;
            }))
            ->andReturn(false);

        $command = $this->app->make(ClearModelCacheCommand::class);
        $command->setLaravel($this->app);

        $command->run(new ArrayInput([
            'model' => ThrowingFlushModel::class,
        ]), $output);

        Mockery::close();
    }
}
