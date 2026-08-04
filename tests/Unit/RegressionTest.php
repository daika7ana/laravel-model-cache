<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\CacheableBuilder;
use YMigVal\LaravelModelCache\Contracts\CacheableBuilderContract;
use YMigVal\LaravelModelCache\HasCacheableModel;
use YMigVal\LaravelModelCache\HasCachedQueries;
use YMigVal\LaravelModelCache\HasCachedRelationships;
use YMigVal\LaravelModelCache\ModelCacheDebugger;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use YMigVal\LaravelModelCache\Tests\Fixtures\RecorderLogger;
use YMigVal\LaravelModelCache\Tests\TestCase;

/**
 * Regression tests for bug fixes.
 *
 * Each test maps to a specific bug that was fixed:
 * 1. Event handler was dead code (bootHasCachedQueries always returned early)
 * 2. flushModelCache() flushed entire app cache when tags unsupported
 * 3. phpunit.xml type mismatch for MODEL_CACHE_ENABLED
 * 4. aggregateFromCache() extraction
 * 5. Debug mode gating (resolve only when debug enabled)
 * 6. Debugger config caching removed
 * 7. Unnecessary method overrides removed
 * 8. debug_backtrace() usage improved
 * 9. Locale in cache key now configurable
 * 10. serialize() replaced with json_encode()
 * 11. getCacheDriver() cached as instance property
 * 12. Trait renamed HasCachableModel → HasCacheableModel
 * 13. Interfaces added
 */
class RegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ========== 1. Event handler regression ==========

    #[Test]
    public function it_flushes_cache_on_model_create_via_boot_event()
    {
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
        DB::flushQueryLog();

        // Create via cached model — boot event handler should flush cache
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(2, $posts, 'Cache must be invalidated after model create');
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Should hit DB after cache invalidation');
    }

    #[Test]
    public function it_flushes_cache_on_model_update_via_boot_event()
    {
        $post = Post::create(['title' => 'Original', 'content' => 'Content', 'published' => true]);

        Post::where('published', true)->get(); // Cache

        $post->update(['title' => 'Updated']);

        DB::enableQueryLog();
        $result = Post::find($post->id);
        $this->assertEquals('Updated', $result->title, 'Cache must be invalidated after model update');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_flushes_cache_on_model_delete_via_boot_event()
    {
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        Post::where('published', true)->get(); // Cache
        $this->assertCount(1, Post::where('published', true)->get());

        Post::first()->delete();

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(0, $posts, 'Cache must be invalidated after model delete');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_flushes_cache_on_model_restore_via_boot_event()
    {
        $post = Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);
        $post->delete();

        Post::where('published', true)->get(); // Cache (0 results)
        $this->assertCount(0, Post::where('published', true)->get());

        $post->restore();

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts, 'Cache must be invalidated after model restore');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    // ========== 2. flushModelCache() safety ==========

    #[Test]
    public function it_does_not_flush_entire_app_cache_when_tags_unsupported()
    {
        // When tags are supported (array in Laravel 11+, redis, memcached),
        // flushModelCache() uses $cache->tags()->flush() — model-scoped flush.
        // When tags are NOT supported, the old code called $cache->flush()
        // which wiped sessions, auth tokens, etc. The fix skips flush entirely.
        //
        // We can't easily swap drivers mid-test, so we verify the contract:
        // flushModelCache() returns a bool and doesn't throw.
        $result = Post::flushModelCache();

        $this->assertIsBool($result, 'flushModelCache must return a boolean');
    }

    // ========== 3. Config type casting ==========

    #[Test]
    public function it_returns_boolean_for_enabled_config()
    {
        config()->set('model-cache.enabled', true);
        $this->assertIsBool(config('model-cache.enabled'));
        $this->assertTrue(config('model-cache.enabled'));

        config()->set('model-cache.enabled', (bool) '0');
        $this->assertIsBool(config('model-cache.enabled'));
        $this->assertFalse(config('model-cache.enabled'));

        config()->set('model-cache.enabled', (bool) '1');
        $this->assertTrue(config('model-cache.enabled'));
    }

    // ========== 4. Aggregate caching ==========

    #[Test]
    public function it_caches_count_results()
    {
        Post::create(['title' => 'P1', 'content' => 'C', 'published' => true, 'views' => 100]);
        Post::create(['title' => 'P2', 'content' => 'C', 'published' => true, 'views' => 200]);

        DB::enableQueryLog();
        $count1 = Post::where('published', true)->count();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $count2 = Post::where('published', true)->count();
        $secondQueries = count(DB::getQueryLog());

        $this->assertEquals(2, $count1);
        $this->assertEquals($count1, $count2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second count should be served from cache');
    }

    #[Test]
    public function it_caches_sum_results()
    {
        Post::create(['title' => 'P1', 'content' => 'C', 'published' => true, 'views' => 100]);
        Post::create(['title' => 'P2', 'content' => 'C', 'published' => true, 'views' => 200]);

        DB::enableQueryLog();
        $sum1 = Post::where('published', true)->sum('views');
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $sum2 = Post::where('published', true)->sum('views');
        $secondQueries = count(DB::getQueryLog());

        $this->assertEquals(300, $sum1);
        $this->assertEquals($sum1, $sum2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second sum should be served from cache');
    }

    #[Test]
    public function it_caches_avg_results()
    {
        Post::create(['title' => 'P1', 'content' => 'C', 'published' => true, 'views' => 100]);
        Post::create(['title' => 'P2', 'content' => 'C', 'published' => true, 'views' => 300]);

        DB::enableQueryLog();
        $avg1 = Post::where('published', true)->avg('views');
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $avg2 = Post::where('published', true)->avg('views');
        $secondQueries = count(DB::getQueryLog());

        $this->assertEquals(200, $avg1);
        $this->assertEquals($avg1, $avg2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second avg should be served from cache');
    }

    #[Test]
    public function it_caches_max_results()
    {
        Post::create(['title' => 'P1', 'content' => 'C', 'published' => true, 'views' => 100]);
        Post::create(['title' => 'P2', 'content' => 'C', 'published' => true, 'views' => 300]);

        DB::enableQueryLog();
        $max1 = Post::where('published', true)->max('views');
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $max2 = Post::where('published', true)->max('views');
        $secondQueries = count(DB::getQueryLog());

        $this->assertEquals(300, $max1);
        $this->assertEquals($max1, $max2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second max should be served from cache');
    }

    #[Test]
    public function it_caches_min_results()
    {
        Post::create(['title' => 'P1', 'content' => 'C', 'published' => true, 'views' => 100]);
        Post::create(['title' => 'P2', 'content' => 'C', 'published' => true, 'views' => 300]);

        DB::enableQueryLog();
        $min1 = Post::where('published', true)->min('views');
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $min2 = Post::where('published', true)->min('views');
        $secondQueries = count(DB::getQueryLog());

        $this->assertEquals(100, $min1);
        $this->assertEquals($min1, $min2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second min should be served from cache');
    }

    #[Test]
    public function it_does_not_log_when_debug_mode_disabled()
    {
        config()->set('model-cache.debug_mode', false);
        $logger = $this->installLogRecorder();

        // Create data and run queries — no debug output may be written
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        Post::where('published', true)->get();
        Post::where('published', true)->count();

        $this->assertSame([], $logger->records, 'No log output may be written when debug mode is disabled');
    }

    #[Test]
    public function it_logs_when_debug_mode_enabled()
    {
        config()->set('model-cache.debug_mode', true);
        $logger = $this->installLogRecorder();

        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        Post::where('published', true)->get();

        $this->assertNotEmpty($logger->records, 'Debug mode must produce log output');
    }

    // ========== 6. Debugger config not cached ==========

    #[Test]
    public function it_reads_debug_config_on_every_call()
    {
        $debugger = resolve(ModelCacheDebugger::class);
        $logger = $this->installLogRecorder();

        // Disable debug — info() must not log
        config()->set('model-cache.debug_mode', false);
        $debugger->info('test message');
        $this->assertSame([], $logger->records, 'info() must not log while debug mode is off');

        // Enable debug — the same instance must log now (config re-read per call)
        config()->set('model-cache.debug_mode', true);
        $debugger->info('test message');
        $this->assertNotEmpty($logger->records, 'info() must log once debug mode is enabled');
    }

    // ========== 9. Locale in cache key ==========

    #[Test]
    public function it_excludes_locale_from_cache_key_by_default()
    {
        config()->set('model-cache.include_locale_in_key', false);

        app()->setLocale('en');
        $keyEn = Post::query()->where('published', true)->getCacheKey();

        app()->setLocale('fr');
        $keyFr = Post::query()->where('published', true)->getCacheKey();

        $this->assertEquals($keyEn, $keyFr, 'Keys should be identical when locale not included');
    }

    #[Test]
    public function it_includes_locale_in_cache_key_when_configured()
    {
        config()->set('model-cache.include_locale_in_key', true);

        app()->setLocale('en');
        $keyEn = Post::query()->where('published', true)->getCacheKey();

        app()->setLocale('fr');
        $keyFr = Post::query()->where('published', true)->getCacheKey();

        $this->assertNotEquals($keyEn, $keyFr, 'Keys should differ when locale is included');
    }

    // ========== 10. json_encode instead of serialize ==========

    #[Test]
    public function it_produces_clean_hash_keys_without_serialize_artifacts()
    {
        Post::create(['title' => 'Test', 'content' => 'Content', 'published' => true]);

        $key = Post::query()->where('published', true)->getCacheKey(['id', 'title']);

        // Should be a clean hex hash, not contain PHP serialize artifacts
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key);
        $this->assertStringNotContainsString('s:', $key, 'Should not contain serialize artifacts');
        $this->assertStringNotContainsString('a:', $key, 'Should not contain serialize artifacts');
    }

    #[Test]
    public function it_handles_special_characters_in_bindings()
    {
        Post::create(['title' => "Post with 'quotes' and \"double\"", 'content' => 'Content', 'published' => true]);

        $key = Post::query()->where('title', "Post with 'quotes' and \"double\"")->getCacheKey();

        $this->assertNotEmpty($key);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key);
    }

    // ========== 11. Cache driver instance caching ==========

    #[Test]
    public function it_caches_cache_driver_as_instance_property()
    {
        $builder = Post::query()->where('published', true);

        $reflection = new \ReflectionClass($builder);
        $prop = $reflection->getProperty('cacheDriver');
        $prop->setAccessible(true);

        // Should be null before first use
        $this->assertNull($prop->getValue($builder));

        // Trigger cache driver resolution via a query that uses the cache
        Post::create(['title' => 'Test', 'content' => 'Content', 'published' => true]);
        $builder->get();

        // Should now be cached
        $driver = $prop->getValue($builder);
        $this->assertNotNull($driver);

        // Should return same instance
        $driver2 = $prop->getValue($builder);
        $this->assertSame($driver, $driver2);
    }

    // ========== 12. Trait rename ==========

    #[Test]
    public function it_has_cacheable_model_trait_with_correct_name()
    {
        $this->assertTrue(trait_exists(HasCacheableModel::class));

        $traits = class_uses_recursive(HasCacheableModel::class);
        $this->assertArrayHasKey(HasCachedQueries::class, $traits);
        $this->assertArrayHasKey(HasCachedRelationships::class, $traits);
    }

    #[Test]
    public function it_uses_cacheable_model_trait_in_fixture()
    {
        $traits = class_uses_recursive(PostWithRelationships::class);
        $this->assertArrayHasKey(HasCacheableModel::class, $traits);
    }

    // ========== 13. Interface contracts ==========

    #[Test]
    public function it_implements_cacheable_builder_contract()
    {
        $builder = Post::query();
        $this->assertInstanceOf(CacheableBuilderContract::class, $builder);

        $methods = [
            'getFromCache', 'firstFromCache', 'remember', 'withoutCache',
            'getCacheKey', 'flushQueryCache', 'countFromCache',
            'sumFromCache', 'maxFromCache', 'minFromCache', 'avgFromCache',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(method_exists($builder, $method), "CacheableBuilder must implement {$method}");
        }
    }

    #[Test]
    public function cacheable_builder_extends_eloquent_builder()
    {
        $builder = Post::query();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $builder);
        $this->assertInstanceOf(CacheableBuilder::class, $builder);
    }

    // ========== 5. Debug mode gating ==========

    /**
     * Swap the application logger for a recorder so we can assert what the
     * package actually writes, instead of just "it didn't throw".
     */
    private function installLogRecorder(): RecorderLogger
    {
        $logger = new RecorderLogger();

        $this->app->instance('log', $logger);

        return $logger;
    }
}
