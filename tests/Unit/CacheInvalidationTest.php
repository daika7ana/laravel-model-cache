<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithoutCache;
use YMigVal\LaravelModelCache\Tests\TestCase;

/**
 * Test suite for cache invalidation mechanisms.
 *
 * This test suite validates cache behavior using two parallel fixture models:
 * - Post (with caching via HasCachedQueries trait)
 * - PostWithoutCache (without caching, same table)
 *
 * Tests are organized into three categories:
 *
 * 1. Proof of Concept:
 *    - Validates that caching works and returns stale data when not invalidated
 *
 * 2. Model Event-Based Invalidation (via Eloquent model events):
 *    - Tests for create, update, delete, and restore operations on model instances
 *    - Triggered by HasCachedQueries::bootHasCachedQueries() event listeners
 *
 * 3. Builder Override-Based Invalidation (via CacheableBuilder method overrides):
 *    - Tests for mass operations like update(), delete(), insert(), increment(), decrement()
 *    - Overridden builder methods that trigger cache invalidation
 *
 * By modifying data through PostWithoutCache and querying through Post, we verify:
 * - Caching is actually working (stale data is returned when cache not invalidated)
 * - Cache invalidation works correctly (fresh data after proper cache flush)
 */
class CacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    // ========== Proof of Concept ==========

    #[Test]
    public function it_proves_caching_works_by_returning_stale_data_when_modified_via_non_cached_model()
    {
        // Create a post using the cached model
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
            'published' => true,
        ]);

        // Query with cached model - first query hits DB and caches result
        DB::enableQueryLog();
        $cachedResult1 = Post::find($post->id);
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertEquals('Original Title', $cachedResult1->title);
        $this->assertGreaterThan(0, $firstQueryCount, 'First query should hit database');

        // Query again with cached model - should be served from cache
        $cachedResult2 = Post::find($post->id);
        $this->assertEquals('Original Title', $cachedResult2->title);

        // CRITICAL TEST: Modify via non-cached model (bypasses cache invalidation)
        PostWithoutCache::where('id', $post->id)->update(['title' => 'Modified Title']);

        // Query with cached model - should still return OLD data (proving cache exists)
        $staleResult = Post::find($post->id);
        $this->assertEquals('Original Title', $staleResult->title, 'CRITICAL: Should return STALE data because cache was not invalidated');

        // Verify the data WAS actually changed in the database
        $freshResult = PostWithoutCache::find($post->id);
        $this->assertEquals('Modified Title', $freshResult->title, 'Database should have the new value');
    }

    // ========== Model Event-Based Invalidation ==========

    #[Test]
    public function it_proves_cache_invalidation_works_on_model_update()
    {
        // Create a post using cached model
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Content',
            'published' => true,
        ]);

        // Cache the result
        DB::enableQueryLog();
        $result1 = Post::find($post->id);
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertEquals('Original Title', $result1->title);
        $this->assertGreaterThan(0, $firstQueryCount);

        // Query again - should be cached
        $result2 = Post::find($post->id);
        $this->assertEquals('Original Title', $result2->title);

        // Update via CACHED model (should trigger cache invalidation)
        $post->update(['title' => 'Updated Title']);

        // Query again - should hit DB and get fresh data
        DB::enableQueryLog();
        $freshResult = Post::find($post->id);
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEquals('Updated Title', $freshResult->title, 'CRITICAL: Should return FRESH data after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after update should hit database (cache was invalidated)');
    }

    #[Test]
    public function it_proves_cache_invalidation_works_on_model_create()
    {
        // Create first post
        Post::create([
            'title' => 'First Post',
            'content' => 'Content',
            'published' => true,
        ]);

        // Cache the query
        DB::enableQueryLog();
        $result1 = Post::where('published', true)->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertCount(1, $result1);
        $this->assertGreaterThan(0, $firstQueryCount);

        // Query again - should be cached
        $result2 = Post::where('published', true)->get();
        $this->assertCount(1, $result2);

        // Create via non-cached model (should NOT invalidate cache)
        PostWithoutCache::create([
            'title' => 'Second Post',
            'content' => 'Content',
            'published' => true,
        ]);

        // Query with cached model - should still return OLD count (1 post)
        // THIS IS THE CRITICAL TEST: If cache is working, we get stale data
        $staleResult = Post::where('published', true)->get();
        $this->assertCount(1, $staleResult, 'CRITICAL: Should return STALE data (1 post) because cache was not invalidated');

        // Verify database actually has 2 posts
        $actualCount = PostWithoutCache::where('published', true)->count();
        $this->assertEquals(2, $actualCount, 'Database should have 2 posts');

        // Now create via CACHED model (should invalidate cache)
        Post::create([
            'title' => 'Third Post',
            'content' => 'Content',
            'published' => true,
        ]);

        // Query again - should hit DB and get fresh data (3 posts)
        DB::enableQueryLog();
        $freshResult = Post::where('published', true)->get();
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(3, $freshResult, 'Should return FRESH data (3 posts) after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after create should hit database (cache was invalidated)');
    }

    #[Test]
    public function it_proves_cache_invalidation_works_on_model_delete()
    {
        // Create posts
        $post1 = Post::create([
            'title' => 'Post 1',
            'content' => 'Content',
            'published' => true,
        ]);

        $post2 = Post::create([
            'title' => 'Post 2',
            'content' => 'Content',
            'published' => true,
        ]);

        // Cache the query
        DB::enableQueryLog();
        $result1 = Post::where('published', true)->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertCount(2, $result1);
        $this->assertGreaterThan(0, $firstQueryCount);

        // Query again - should be cached
        $result2 = Post::where('published', true)->get();
        $this->assertCount(2, $result2);

        // Delete via non-cached model (should NOT invalidate cache)
        PostWithoutCache::where('id', $post1->id)->delete();

        // Query with cached model - should still return OLD count (2 posts)
        // THIS IS THE CRITICAL TEST: If cache is working, we get stale data
        $staleResult = Post::where('published', true)->get();
        $this->assertCount(2, $staleResult, 'CRITICAL: Should return STALE data (2 posts) because cache was not invalidated');

        // Verify database actually has 1 post
        $actualCount = PostWithoutCache::where('published', true)->count();
        $this->assertEquals(1, $actualCount, 'Database should have 1 post');

        // Now delete via CACHED model (should invalidate cache)
        $post2Instance = Post::find($post2->id);
        $post2Instance->delete();

        // Query again - should hit DB and get fresh data (0 posts)
        DB::enableQueryLog();
        $freshResult = Post::where('published', true)->get();
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(0, $freshResult, 'Should return FRESH data (0 posts) after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after delete should hit database (cache was invalidated)');
    }

    #[Test]
    public function it_proves_cache_invalidation_works_on_model_restore()
    {
        // Create and soft delete a post
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $post->delete();

        // Cache the query (0 posts)
        DB::enableQueryLog();
        $result1 = Post::where('published', true)->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertCount(0, $result1);
        $this->assertGreaterThan(0, $firstQueryCount);

        // Query again - should be cached
        $result2 = Post::where('published', true)->get();
        $this->assertCount(0, $result2);

        // Restore via non-cached model (should NOT invalidate cache)
        PostWithoutCache::withTrashed()->where('id', $post->id)->restore();

        // Query with cached model - should still return OLD count (0 posts)
        // THIS IS THE CRITICAL TEST: If cache is working, we get stale data
        $staleResult = Post::where('published', true)->get();
        $this->assertCount(0, $staleResult, 'CRITICAL: Should return STALE data (0 posts) because cache was not invalidated');

        // Verify database actually has 1 post
        $actualCount = PostWithoutCache::where('published', true)->count();
        $this->assertEquals(1, $actualCount, 'Database should have 1 restored post');

        // Delete and restore again via CACHED model (should invalidate cache)
        $postInstance = Post::find($post->id);
        $postInstance->delete();
        $postInstance->restore();

        // Query again - should hit DB and get fresh data (1 post)
        DB::enableQueryLog();
        $freshResult = Post::where('published', true)->get();
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(1, $freshResult, 'Should return FRESH data (1 post) after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after restore should hit database (cache was invalidated)');
    }

    // ========== Builder Override-Based Invalidation ==========

    #[Test]
    public function it_invalidates_cache_on_mass_update()
    {
        // Create test data
        Post::create([
            'title' => 'Post 1',
            'content' => 'Content 1',
            'published' => false,
        ]);

        Post::create([
            'title' => 'Post 2',
            'content' => 'Content 2',
            'published' => false,
        ]);

        // Cache the query
        $posts = Post::where('published', false)->get();
        $this->assertCount(2, $posts);

        // Second query - should be cached
        $cachedPosts = Post::where('published', false)->get();
        $this->assertCount(2, $cachedPosts);

        // Mass update via non-cached model (should NOT invalidate cache)
        PostWithoutCache::where('published', false)->limit(1)->update(['published' => true]);

        // Query with cached model - should still return OLD data (2 unpublished)
        $staleResult = Post::where('published', false)->get();
        $this->assertCount(2, $staleResult, 'CRITICAL: Should return STALE data because cache was not invalidated');

        // Verify database was actually updated
        $actualCount = PostWithoutCache::where('published', false)->count();
        $this->assertEquals(1, $actualCount, 'Database should have 1 unpublished post');

        // Mass update via CACHED model (should invalidate cache)
        Post::where('published', false)->update(['published' => true]);

        // Query again - should hit DB and get fresh data
        DB::enableQueryLog();
        $freshResult = Post::where('published', false)->get();
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(0, $freshResult, 'CRITICAL: Should return FRESH data (0 unpublished) after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after mass update should hit database (cache invalidated)');
    }

    #[Test]
    public function it_invalidates_cache_on_mass_delete()
    {
        // Create test data
        Post::create([
            'title' => 'Post 1',
            'content' => 'Content 1',
            'published' => true,
        ]);

        Post::create([
            'title' => 'Post 2',
            'content' => 'Content 2',
            'published' => true,
        ]);

        // Cache the query
        $posts = Post::where('published', true)->get();
        $this->assertCount(2, $posts);

        // Second query - should be cached
        $cachedPosts = Post::where('published', true)->get();
        $this->assertCount(2, $cachedPosts);

        // Mass delete via non-cached model (should NOT invalidate cache)
        PostWithoutCache::where('published', true)->limit(1)->delete();

        // Query with cached model - should still return OLD data (2 posts)
        $staleResult = Post::where('published', true)->get();
        $this->assertCount(2, $staleResult, 'CRITICAL: Should return STALE data because cache was not invalidated');

        // Verify database was actually updated
        $actualCount = PostWithoutCache::where('published', true)->count();
        $this->assertEquals(1, $actualCount, 'Database should have 1 post');

        // Mass delete via CACHED model (should invalidate cache)
        Post::where('published', true)->delete();

        // Query again - should hit DB and get fresh data
        DB::enableQueryLog();
        $freshPosts = Post::where('published', true)->get();
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(0, $freshPosts, 'CRITICAL: Should return FRESH data after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after mass delete should hit database (cache invalidated)');
    }

    #[Test]
    public function it_can_bypass_cache_with_manual_flush()
    {
        // Create test data
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Content',
            'published' => true,
        ]);

        // Cache the query
        $cachedPost = Post::where('id', $post->id)->first();
        $this->assertEquals('Original Title', $cachedPost->title);

        // Update directly in database (bypass model events)
        DB::table('posts')->where('id', $post->id)->update(['title' => 'Updated Title']);

        // Query with cache - should still get old title
        $cachedPost = Post::where('id', $post->id)->first();
        $this->assertEquals('Original Title', $cachedPost->title);

        // Clear cache manually
        Post::flushModelCache();

        // Query again - should get new title
        $freshPost = Post::where('id', $post->id)->first();
        $this->assertEquals('Updated Title', $freshPost->title);
    }

    #[Test]
    public function it_invalidates_cache_on_insert()
    {
        // Cache empty query
        $posts = Post::where('published', true)->get();
        $this->assertCount(0, $posts);

        // Second query - should be cached
        $cachedPosts = Post::where('published', true)->get();
        $this->assertCount(0, $cachedPosts);

        // Insert via non-cached model (should NOT invalidate cache)
        PostWithoutCache::query()->insert([
            'title' => 'First Insert',
            'content' => 'Content',
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query with cached model - should still return OLD data (0 posts)
        $staleResult = Post::where('published', true)->get();
        $this->assertCount(0, $staleResult, 'CRITICAL: Should return STALE data because cache was not invalidated');

        // Verify database was actually updated
        $actualCount = PostWithoutCache::where('published', true)->count();
        $this->assertEquals(1, $actualCount, 'Database should have 1 post');

        // Insert via CACHED model (should invalidate cache)
        Post::query()->insert([
            'title' => 'Second Insert',
            'content' => 'Content',
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query again - should hit DB and get fresh data
        DB::enableQueryLog();
        $freshPosts = Post::where('published', true)->get();
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(2, $freshPosts, 'CRITICAL: Should return FRESH data after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after insert should hit database (cache invalidated)');
    }

    #[Test]
    public function it_handles_increment_with_cache_invalidation()
    {
        // Create test data
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Content',
            'published' => true,
            'views' => 100,
        ]);

        // Cache the result
        $cachedPost = Post::find($post->id);
        $this->assertEquals(100, $cachedPost->views);

        // Second query - should be cached
        $cachedPostAgain = Post::find($post->id);
        $this->assertEquals(100, $cachedPostAgain->views);

        // Increment via non-cached model (should NOT invalidate cache)
        PostWithoutCache::where('id', $post->id)->increment('views', 25);

        // Query with cached model - should still return OLD value
        $staleResult = Post::find($post->id);
        $this->assertEquals(100, $staleResult->views, 'CRITICAL: Should return STALE data because cache was not invalidated');

        // Verify database was actually updated
        $actualValue = PostWithoutCache::find($post->id)->views;
        $this->assertEquals(125, $actualValue, 'Database should have incremented value');

        // Increment via CACHED model (should invalidate cache)
        Post::where('id', $post->id)->increment('views', 25);

        // Query again - should hit DB and get fresh data
        DB::enableQueryLog();
        $updatedPost = Post::find($post->id);
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEquals(150, $updatedPost->views, 'CRITICAL: Should return FRESH data after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after increment should hit database (cache invalidated)');
    }

    #[Test]
    public function it_handles_decrement_with_cache_invalidation()
    {
        // Create test data
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Content',
            'published' => true,
            'views' => 100,
        ]);

        // Cache the result
        $cachedPost = Post::find($post->id);
        $this->assertEquals(100, $cachedPost->views);

        // Second query - should be cached
        $cachedPostAgain = Post::find($post->id);
        $this->assertEquals(100, $cachedPostAgain->views);

        // Decrement via non-cached model (should NOT invalidate cache)
        PostWithoutCache::where('id', $post->id)->decrement('views', 20);

        // Query with cached model - should still return OLD value
        $staleResult = Post::find($post->id);
        $this->assertEquals(100, $staleResult->views, 'CRITICAL: Should return STALE data because cache was not invalidated');

        // Verify database was actually updated
        $actualValue = PostWithoutCache::find($post->id)->views;
        $this->assertEquals(80, $actualValue, 'Database should have decremented value');

        // Decrement via CACHED model (should invalidate cache)
        Post::where('id', $post->id)->decrement('views', 10);

        // Query again - should hit DB and get fresh data
        DB::enableQueryLog();
        $updatedPost = Post::find($post->id);
        $freshQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEquals(70, $updatedPost->views, 'CRITICAL: Should return FRESH data after cache invalidation');
        $this->assertGreaterThan(0, $freshQueryCount, 'Query after decrement should hit database (cache invalidated)');
    }
}
