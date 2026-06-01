<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithCustomCache;
use YMigVal\LaravelModelCache\Tests\TestCase;

/**
 * Functional coverage and edge case tests.
 *
 * Covers:
 * - Caching disabled globally
 * - withoutCache() method
 * - paginateFromCache()
 * - Builder operations: forceDelete, insertOrIgnore, upsert, updateOrInsert, truncate, touch, restore
 * - Empty results caching
 * - Special characters and unicode in bindings
 * - Config fallbacks and custom cache stores
 * - Cache key determinism and column list uniqueness
 */
class FunctionalCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ========== Caching disabled ==========

    #[Test]
    public function it_bypasses_cache_when_globally_disabled()
    {
        config()->set('model-cache.enabled', false);

        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        Post::where('published', true)->get();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        Post::where('published', true)->get();
        $secondQueries = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $firstQueries);
        $this->assertGreaterThan(0, $secondQueries, 'Both queries should hit DB when caching disabled');
    }

    #[Test]
    public function it_bypasses_first_from_cache_when_globally_disabled()
    {
        config()->set('model-cache.enabled', false);

        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        $result = Post::where('published', true)->firstFromCache();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        Post::where('published', true)->firstFromCache();
        $secondQueries = count(DB::getQueryLog());

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertGreaterThan(0, $secondQueries, 'Both queries should hit DB when caching disabled');
    }

    // ========== withoutCache() ==========

    #[Test]
    public function it_bypasses_cache_with_without_cache_method()
    {
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        // First query — caches result
        Post::where('published', true)->get();

        // Second query with withoutCache — should hit DB
        DB::enableQueryLog();
        Post::where('published', true)->withoutCache()->get();
        $queries = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $queries, 'withoutCache() should always hit DB');
    }

    #[Test]
    public function it_disables_remember_when_globally_disabled()
    {
        config()->set('model-cache.enabled', false);

        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        Post::where('published', true)->remember(30)->get();
        $queries = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $queries, 'remember() should not cache when globally disabled');
    }

    // ========== paginateFromCache() ==========

    #[Test]
    public function it_caches_paginate_results()
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        DB::enableQueryLog();
        $page1 = Post::where('published', true)->paginateFromCache(10);
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $page2 = Post::where('published', true)->paginateFromCache(10);
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(10, $page1);
        $this->assertCount(10, $page2);
        $this->assertEquals($page1->first()->id, $page2->first()->id);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second paginate should be served from cache');
    }

    #[Test]
    public function it_caches_different_pages_separately()
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        $page1 = Post::where('published', true)->paginateFromCache(10, ['*'], 'page', 1);
        $page2 = Post::where('published', true)->paginateFromCache(10, ['*'], 'page', 2);

        $this->assertNotEquals($page1->first()->id, $page2->first()->id, 'Different pages should have different data');
    }

    // ========== Builder operations ==========

    #[Test]
    public function it_invalidates_cache_on_force_delete()
    {
        $post = Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        Post::where('published', true)->get(); // Cache
        $this->assertCount(1, Post::where('published', true)->get());

        Post::withTrashed()->where('id', $post->id)->forceDelete();

        DB::enableQueryLog();
        $posts = Post::withTrashed()->where('id', $post->id)->get();
        $this->assertCount(0, $posts, 'Cache should be invalidated after forceDelete');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_invalidates_cache_on_insert_or_ignore()
    {
        Post::where('published', true)->get(); // Cache (empty)

        Post::query()->insertOrIgnore([
            ['title' => 'Inserted', 'content' => 'Content', 'published' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertGreaterThan(0, $posts->count(), 'Cache should be invalidated after insertOrIgnore');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_invalidates_cache_on_upsert()
    {
        Post::where('published', true)->get(); // Cache (empty)

        Post::query()->upsert(
            [['title' => 'Upserted', 'content' => 'Content', 'published' => true, 'created_at' => now(), 'updated_at' => now()]],
            ['id'],
        );

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertGreaterThan(0, $posts->count(), 'Cache should be invalidated after upsert');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_invalidates_cache_on_update_or_insert()
    {
        Post::where('published', true)->get(); // Cache (empty)

        Post::query()->updateOrInsert(
            ['title' => 'New Post'],
            ['content' => 'Content', 'published' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertGreaterThan(0, $posts->count(), 'Cache should be invalidated after updateOrInsert');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_invalidates_cache_on_truncate()
    {
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        Post::where('published', true)->get(); // Cache
        $this->assertCount(1, Post::where('published', true)->get());

        Post::query()->truncate();

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(0, $posts, 'Cache should be invalidated after truncate');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_invalidates_cache_on_touch()
    {
        $post = Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        Post::where('published', true)->get(); // Cache

        // Touch the model's updated_at
        $post->touch();

        DB::enableQueryLog();
        $fresh = Post::find($post->id);
        $this->assertNotNull($fresh);
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'Cache should be invalidated after touch');
    }

    #[Test]
    public function it_invalidates_cache_on_builder_restore()
    {
        $post = Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);
        $post->delete();

        Post::where('published', true)->get(); // Cache (0 results)
        $this->assertCount(0, Post::where('published', true)->get());

        Post::withTrashed()->where('id', $post->id)->restore();

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts, 'Cache should be invalidated after builder restore');
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    // ========== Edge cases ==========

    #[Test]
    public function it_caches_empty_query_results()
    {
        DB::enableQueryLog();
        Post::where('published', true)->get(); // Empty
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        Post::where('published', true)->get(); // Should be cached
        $secondQueries = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Empty results should be cached');
    }

    #[Test]
    public function it_caches_null_first_result()
    {
        DB::enableQueryLog();
        $result = Post::where('published', true)->firstFromCache();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $result2 = Post::where('published', true)->firstFromCache();
        $secondQueries = count(DB::getQueryLog());

        $this->assertNull($result);
        $this->assertNull($result2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Null first result should be cached');
    }

    #[Test]
    public function it_handles_unicode_bindings_in_cache_key()
    {
        Post::create(['title' => '日本語のタイトル', 'content' => 'Content', 'published' => true]);

        $key = Post::query()->where('title', '日本語のタイトル')->getCacheKey();

        $this->assertNotEmpty($key);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key);
    }

    #[Test]
    public function it_produces_fixed_length_key_for_long_queries()
    {
        $query = Post::query();
        for ($i = 0; $i < 50; $i++) {
            $query->where("column_{$i}", "=", "value_{$i}");
        }

        $key = $query->getCacheKey();

        $this->assertLessThan(256, strlen($key), 'Cache key should be reasonable length');
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key);
    }

    #[Test]
    public function it_uses_config_cache_duration_as_fallback()
    {
        config()->set('model-cache.cache_duration', 30);

        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        // Should work without setting cacheMinutes on model
        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        Post::where('published', true)->get();
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(1, $posts);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Should use config cache_duration as fallback');
    }

    #[Test]
    public function it_uses_custom_cache_store_when_configured()
    {
        config()->set('model-cache.cache_store', 'array');

        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        // Should work with custom store
        DB::enableQueryLog();
        Post::where('published', true)->get();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        Post::where('published', true)->get();
        $secondQueries = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Should cache using custom store');
    }

    #[Test]
    public function it_generates_deterministic_cache_keys()
    {
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        $key1 = Post::query()->where('published', true)->where('views', '>', 100)->getCacheKey();
        $key2 = Post::query()->where('published', true)->where('views', '>', 100)->getCacheKey();

        $this->assertEquals($key1, $key2, 'Same query should produce same cache key');
    }

    #[Test]
    public function it_generates_different_keys_for_different_columns()
    {
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        $keyAll = Post::query()->where('published', true)->getCacheKey(['*']);
        $keySpecific = Post::query()->where('published', true)->getCacheKey(['id', 'title']);

        $this->assertNotEquals($keyAll, $keySpecific, 'Different column lists should produce different keys');
    }

    #[Test]
    public function it_generates_different_keys_for_different_models_same_table()
    {
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        $key1 = Post::query()->where('published', true)->getCacheKey();
        $key2 = PostWithCustomCache::query()->where('published', true)->getCacheKey();

        $this->assertNotEquals($key1, $key2, 'Different models on same table should have different cache keys');
    }

    #[Test]
    public function it_returns_identical_data_from_cache_and_db()
    {
        Post::create(['title' => 'Test', 'content' => 'Content', 'published' => true, 'views' => 42]);

        // Fresh from DB
        $fresh = Post::where('published', true)->get()->toArray();

        // From cache
        $cached = Post::where('published', true)->get()->toArray();

        $this->assertEquals($fresh, $cached, 'Cached data should be identical to fresh data');
    }

    #[Test]
    public function it_handles_raw_expressions_in_query()
    {
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true, 'views' => 100]);

        $posts = Post::whereRaw('views > ?', [50])->get();
        $this->assertCount(1, $posts);

        // Cached call
        $cached = Post::whereRaw('views > ?', [50])->get();
        $this->assertCount(1, $cached);
    }
}
