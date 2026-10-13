<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithCustomCache;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use Daika7ana\LaravelModelCache\Tests\TestCase;

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

    #[Test]
    public function it_bypasses_cache_with_without_cache_on_paginate_from_cache()
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        DB::enableQueryLog();
        $page1 = Post::where('published', true)->withoutCache()->paginateFromCache(10);
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $page2 = Post::where('published', true)->withoutCache()->paginateFromCache(10);
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(10, $page1);
        $this->assertCount(10, $page2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertGreaterThan(0, $secondQueries, 'withoutCache() paginate should always hit the database');
    }

    #[Test]
    public function it_keeps_paginator_links_per_request_path()
    {
        for ($i = 1; $i <= 3; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        // Same query, two different routes: the second must not reuse the first
        // paginator (whose pagination links point at the first path).
        \Illuminate\Pagination\Paginator::currentPathResolver(fn() => '/posts');
        $posts = Post::where('published', true)->paginateFromCache(10);

        \Illuminate\Pagination\Paginator::currentPathResolver(fn() => '/admin/posts');
        $admin = Post::where('published', true)->paginateFromCache(10);

        // Restore the default path resolver for the rest of the suite.
        \Illuminate\Pagination\Paginator::currentPathResolver(fn() => '/');

        $this->assertSame('/posts', $posts->path());
        $this->assertSame('/admin/posts', $admin->path());
    }

    #[Test]
    public function it_auto_caches_plain_paginate()
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        DB::enableQueryLog();
        $page1 = Post::where('published', true)->paginate(10);
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $page2 = Post::where('published', true)->paginate(10);
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(10, $page1);
        $this->assertCount(10, $page2);
        $this->assertEquals($page1->first()->id, $page2->first()->id);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Second paginate should be served from cache');
    }

    #[Test]
    public function it_bypasses_cache_with_without_cache_on_plain_paginate()
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        DB::enableQueryLog();
        $page1 = Post::where('published', true)->withoutCache()->paginate(10);
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $page2 = Post::where('published', true)->withoutCache()->paginate(10);
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(10, $page1);
        $this->assertCount(10, $page2);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertGreaterThan(0, $secondQueries, 'withoutCache() paginate should always hit the database');
    }

    #[Test]
    public function it_accepts_custom_total_on_paginate_from_cache()
    {
        for ($i = 1; $i <= 20; $i++) {
            Post::create(['title' => "Post $i", 'content' => "Content $i", 'published' => true]);
        }

        DB::enableQueryLog();
        $page1 = Post::where('published', true)->paginateFromCache(10, ['*'], 'page', 1, 999);
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertCount(10, $page1);
        $this->assertEquals(999, $page1->total(), 'Custom total should be respected');

        DB::enableQueryLog();
        $page2 = Post::where('published', true)->paginateFromCache(10, ['*'], 'page', 1, 999);
        $secondQueries = count(DB::getQueryLog());

        $this->assertEquals(999, $page2->total());
        $this->assertEquals(0, $secondQueries, 'Custom-total paginate should be served from cache');
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
    public function it_returns_the_touch_result_from_builder_touch()
    {
        $post = Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        // Builder touch() must return the number of affected rows like the parent
        $result = Post::query()->where('id', $post->id)->touch();

        $this->assertEquals(1, $result, 'Builder touch should return the number of affected rows');
    }

    #[Test]
    public function it_returns_zero_from_builder_touch_when_no_rows_match()
    {
        // No matching row — parent returns 0
        $result = Post::query()->where('id', 999999)->touch();

        $this->assertEquals(0, $result, 'Builder touch should return 0 when no rows are affected');
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

        $keyPost = Post::query()->where('published', true)->getCacheKey();
        $keyCustom = PostWithCustomCache::query()->where('published', true)->getCacheKey();

        // Both of these share the `posts` table AND the default key prefix.
        $keyRelationships = PostWithRelationships::query()->where('published', true)->getCacheKey();

        $this->assertNotSame($keyPost, $keyCustom, 'Different models on same table should have different cache keys');
        $this->assertNotSame($keyPost, $keyRelationships, 'Two default-prefix models on the same table must not share a key');
    }

    #[Test]
    public function it_generates_different_keys_for_different_connections()
    {
        config()->set('database.connections.secondary', config('database.connections.' . config('database.default')));

        $keyDefault = Post::query()->where('published', true)->getCacheKey();
        $keySecondary = (new Post())->setConnection('secondary')->newQuery()->where('published', true)->getCacheKey();

        $this->assertNotSame($keyDefault, $keySecondary, 'The same query on different connections must not share a key');
    }

    #[Test]
    public function it_generates_the_same_key_regardless_of_eager_load_order()
    {
        $keyAB = PostWithRelationships::with(['author', 'tags'])->getCacheKey();
        $keyBA = PostWithRelationships::with(['tags', 'author'])->getCacheKey();

        $this->assertSame($keyAB, $keyBA, 'Eager-load insertion order must not change the cache key');
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

    #[Test]
    public function it_invalidates_cache_on_increment_each()
    {
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true, 'views' => 0]);

        // Prime the cache
        Post::where('id', $post->id)->get();

        Post::where('id', $post->id)->incrementEach(['views' => 5]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = Post::where('id', $post->id)->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, (int) $fresh->first()->views);
        $this->assertGreaterThan(0, $queries, 'incrementEach() must invalidate the cached query');
    }

    #[Test]
    public function it_invalidates_cache_on_decrement_each()
    {
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true, 'views' => 10]);

        Post::where('id', $post->id)->get();

        Post::where('id', $post->id)->decrementEach(['views' => 4]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = Post::where('id', $post->id)->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(6, (int) $fresh->first()->views);
        $this->assertGreaterThan(0, $queries, 'decrementEach() must invalidate the cached query');
    }

    #[Test]
    public function it_invalidates_cache_on_insert_using()
    {
        Post::create(['title' => 'Source', 'content' => 'Content', 'published' => true, 'views' => 3]);

        // Prime a cached count so we can prove the insert invalidates it.
        $before = Post::where('published', true)->count();
        $this->assertSame(1, $before);

        $inserted = Post::query()->insertUsing(
            ['title', 'content', 'published', 'views'],
            Post::query()->where('published', true)->select('title', 'content', 'published', 'views'),
        );

        $this->assertSame(1, $inserted, 'insertUsing() must return the affected row count');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $after = Post::where('published', true)->count();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, $after, 'insertUsing() must invalidate the cached count');
        $this->assertGreaterThan(0, $queries);
    }
}
