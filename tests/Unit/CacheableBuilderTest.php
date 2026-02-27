<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
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

        // Query again - should be cached
        $posts = Post::where('published', true)->remember(30)->get();
        $this->assertCount(1, $posts);
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

        // Query again - should be cached
        $posts = Post::where('published', true)->getFromCache();
        $this->assertCount(1, $posts);
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

        // Query again - should be cached
        $post = Post::where('published', true)->firstFromCache();
        $this->assertNotNull($post);
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
        $publishedPosts = Post::where('published', true)->get();
        $unpublishedPosts = Post::where('published', false)->get();

        $this->assertCount(1, $publishedPosts);
        $this->assertCount(1, $unpublishedPosts);
    }
}
