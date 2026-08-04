<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\TestCase;

/**
 * Test suite for CacheableBuilder caching functionality.
 *
 * This test suite validates the CacheableBuilder class, which extends Laravel's query builder
 * to add automatic caching support. Tests are organized into two categories:
 *
 * 1. Caching Methods:
 *    - remember(), getFromCache(), firstFromCache()
 *    - Query result caching via various builder methods
 *
 * 2. Cache Key Generation:
 *    - Validates unique cache keys for different queries
 *    - Ensures query-specific and relationship-specific caching
 *
 * Note: Cache invalidation tests (via model events or builder overrides) are consolidated
 * in the dedicated CacheInvalidationTest class to avoid duplication.
 */
class CacheableBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    // ========== Section 1: Caching Methods ==========

    #[Test]
    public function it_uses_remember_method_to_cache_queries()
    {
        // Create test data
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Use remember() method with custom duration
        $posts = Post::where('published', true)->remember(30)->get();
        $this->assertCount(1, $posts);

        // Query again - should be served from the cache (0 database queries)
        DB::enableQueryLog();
        $posts = Post::where('published', true)->remember(30)->get();
        $this->assertCount(1, $posts);
        $this->assertEquals(0, count(DB::getQueryLog()), 'Second remember() query should be served from cache');
        DB::disableQueryLog();
    }

    #[Test]
    public function it_uses_get_from_cache_method()
    {
        // Create test data
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Use getFromCache() method
        $posts = Post::where('published', true)->getFromCache();
        $this->assertCount(1, $posts);

        // Query again - should be served from the cache (0 database queries)
        DB::enableQueryLog();
        $posts = Post::where('published', true)->getFromCache();
        $this->assertCount(1, $posts);
        $this->assertEquals(0, count(DB::getQueryLog()), 'Second getFromCache() query should be served from cache');
        DB::disableQueryLog();
    }

    #[Test]
    public function it_uses_first_from_cache_method()
    {
        // Create test data
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Use firstFromCache() method
        $post = Post::where('published', true)->firstFromCache();
        $this->assertNotNull($post);
        $this->assertEquals('Test Post', $post->title);

        // Query again - should be served from the cache (0 database queries)
        DB::enableQueryLog();
        $post = Post::where('published', true)->firstFromCache();
        $this->assertNotNull($post);
        $this->assertEquals(0, count(DB::getQueryLog()), 'Second firstFromCache() query should be served from cache');
        DB::disableQueryLog();
    }

    // ========== Section 2: Cache Key Generation ==========

    #[Test]
    public function it_generates_unique_cache_keys_for_different_queries()
    {
        // Create test data
        Post::create([
            'title' => 'Published Post',
            'content' => 'Content',
            'published' => true,
        ]);

        Post::create([
            'title' => 'Unpublished Post',
            'content' => 'Content',
            'published' => false,
        ]);

        // Different queries should use different cache keys
        $keyPublished = Post::where('published', true)->getCacheKey();
        $keyUnpublished = Post::where('published', false)->getCacheKey();
        $this->assertNotEquals($keyPublished, $keyUnpublished, 'Different queries must produce different cache keys');

        $publishedPosts = Post::where('published', true)->get();
        $unpublishedPosts = Post::where('published', false)->get();

        $this->assertCount(1, $publishedPosts);
        $this->assertCount(1, $unpublishedPosts);
    }

    #[Test]
    public function it_uses_configured_hash_algorithm_for_cache_keys()
    {
        config()->set('model-cache.hash_algorithm', 'sha1');

        $cacheKey = Post::query()->where('published', true)->getCacheKey();

        $this->assertSame(40, strlen($cacheKey));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $cacheKey);
    }

    #[Test]
    public function it_throws_for_invalid_hash_algorithm()
    {
        config()->set('model-cache.hash_algorithm', 'not-a-real-hash');

        // Same exception type as the provider's boot-time validation (ModelCacheServiceProvider)
        $this->expectException(\InvalidArgumentException::class);

        Post::query()->where('published', true)->getCacheKey();
    }
}
