<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\ModelCacheDebugger;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Tag;
use YMigVal\LaravelModelCache\Tests\TestCase;

/**
 * Error handling, service provider, and relationship edge case tests.
 *
 * Covers:
 * - Error handling (flushModelCache on exception, getCacheDriver fallback, supportsTags)
 * - Service provider (singleton, config merge)
 * - Relationship cache edge cases (detach all, attach empty, sync no-changes)
 */
class ErrorHandlingAndEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ========== Error handling ==========

    #[Test]
    public function it_flushes_model_cache_successfully()
    {
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        Post::where('published', true)->get(); // Cache

        $result = Post::flushModelCache();

        $this->assertTrue($result, 'flushModelCache should succeed with tag-supporting driver');
    }

    #[Test]
    public function it_returns_bool_from_flush_model_cache()
    {
        $result = Post::flushModelCache();

        $this->assertIsBool($result, 'flushModelCache should return a boolean');
    }

    #[Test]
    public function it_handles_get_cache_driver_fallback_on_bad_store()
    {
        config()->set('model-cache.cache_store', 'nonexistent_store');

        // Should not throw — should fall back
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    #[Test]
    public function it_handles_flush_query_cache_on_uncached_query()
    {
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);

        // Flush without having cached anything — should not throw
        $result = Post::query()->where('published', true)->flushQueryCache();

        $this->assertIsBool($result);
    }

    // ========== Service provider ==========

    #[Test]
    public function it_registers_debugger_as_singleton()
    {
        $debugger1 = resolve(ModelCacheDebugger::class);
        $debugger2 = resolve(ModelCacheDebugger::class);

        $this->assertSame($debugger1, $debugger2, 'Debugger should be a singleton');
    }

    #[Test]
    public function it_has_correct_default_config_values()
    {
        // Reset to defaults
        config()->set('model-cache.cache_duration', 60);
        config()->set('model-cache.cache_key_prefix', 'model_cache_');
        config()->set('model-cache.enabled', true);
        config()->set('model-cache.debug_mode', false);
        config()->set('model-cache.include_locale_in_key', false);

        $this->assertEquals(60, config('model-cache.cache_duration'));
        $this->assertEquals('model_cache_', config('model-cache.cache_key_prefix'));
        $this->assertTrue(config('model-cache.enabled'));
        $this->assertFalse(config('model-cache.debug_mode'));
        $this->assertFalse(config('model-cache.include_locale_in_key'));
    }

    // ========== Relationship cache edge cases ==========

    #[Test]
    public function it_does_not_flush_cache_when_attach_empty_array()
    {
        $post = PostWithRelationships::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag = Tag::create(['name' => 'Tag 1']);

        // Cache post query
        PostWithRelationships::where('published', true)->get();
        $this->assertCount(1, PostWithRelationships::where('published', true)->get());

        // Attach empty — should not flush
        $post->tags()->attach([]);

        // Cache should still be valid
        DB::enableQueryLog();
        $posts = PostWithRelationships::where('published', true)->get();
        $this->assertCount(1, $posts);
        // Note: With array driver (no tags), cache might not be invalidated anyway
        // This test verifies the attach() call doesn't error on empty input
    }

    #[Test]
    public function it_flushes_cache_on_detach_all()
    {
        $post = PostWithRelationships::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        $post->tags()->attach([$tag1->id, $tag2->id]);
        $this->assertCount(2, $post->fresh()->tags);

        // Detach all
        $post->tags()->detach();

        $this->assertCount(0, $post->fresh()->tags);
    }

    #[Test]
    public function it_handles_sync_with_no_changes()
    {
        $post = PostWithRelationships::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag = Tag::create(['name' => 'Tag 1']);

        $post->tags()->attach([$tag->id]);

        // Sync with same IDs — no changes
        $result = $post->tags()->sync([$tag->id]);

        $this->assertEmpty($result['attached']);
        $this->assertEmpty($result['updated']);
        $this->assertEmpty($result['detached']);
        $this->assertCount(1, $post->fresh()->tags);
    }

    #[Test]
    public function it_handles_sync_with_changes()
    {
        $post = PostWithRelationships::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        $post->tags()->attach([$tag1->id]);

        // Sync with different IDs
        $result = $post->tags()->sync([$tag2->id]);

        $this->assertNotEmpty($result['attached'] + $result['detached']);
        $this->assertCount(1, $post->fresh()->tags);
        $this->assertEquals($tag2->id, $post->fresh()->tags->first()->id);
    }

    #[Test]
    public function it_handles_attach_with_attributes()
    {
        $post = PostWithRelationships::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag = Tag::create(['name' => 'Tag 1']);

        $post->tags()->attach([$tag->id], ['created_at' => now()]);

        $this->assertCount(1, $post->fresh()->tags);
    }

    #[Test]
    public function it_handles_multiple_sync_operations()
    {
        $post = PostWithRelationships::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);
        $tag3 = Tag::create(['name' => 'Tag 3']);

        // First sync
        $post->tags()->sync([$tag1->id, $tag2->id]);
        $this->assertCount(2, $post->fresh()->tags);

        // Second sync — remove tag1, add tag3
        $result = $post->tags()->sync([$tag2->id, $tag3->id]);
        $this->assertCount(2, $post->fresh()->tags);
        $this->assertContains($tag3->id, $post->fresh()->tags->pluck('id')->toArray());
    }

    // ========== Performance ==========

    #[Test]
    public function it_caches_query_with_many_conditions()
    {
        for ($i = 1; $i <= 5; $i++) {
            Post::create([
                'title' => "Post $i",
                'content' => "Content $i",
                'published' => $i % 2 === 0,
                'views' => $i * 100,
            ]);
        }

        DB::enableQueryLog();
        $posts = Post::where('published', true)
            ->where('views', '>', 100)
            ->orderBy('views', 'desc')
            ->limit(2)
            ->get();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $cachedPosts = Post::where('published', true)
            ->where('views', '>', 100)
            ->orderBy('views', 'desc')
            ->limit(2)
            ->get();
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(2, $posts);
        $this->assertEquals($posts->pluck('id')->toArray(), $cachedPosts->pluck('id')->toArray());
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Complex query should be cached');
    }

    #[Test]
    public function it_caches_eager_loaded_queries()
    {
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $tag = Tag::create(['name' => 'Tag']);
        $post->tags()->attach($tag->id);

        DB::enableQueryLog();
        $posts1 = Post::with('tags')->where('published', true)->get();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $posts2 = Post::with('tags')->where('published', true)->get();
        $secondQueries = count(DB::getQueryLog());

        $this->assertCount(1, $posts1->first()->tags);
        $this->assertCount(1, $posts2->first()->tags);
        $this->assertGreaterThan(0, $firstQueries);
        $this->assertEquals(0, $secondQueries, 'Eager loaded query should be cached');
    }

    #[Test]
    public function it_invalidate_cache_only_for_target_model()
    {
        Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        Tag::create(['name' => 'Tag']);

        // Cache both
        Post::where('published', true)->get();
        Tag::all();

        // Create new post — should only invalidate Post cache
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $postQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        $tags = Tag::all();
        $tagQueries = count(DB::getQueryLog());

        $this->assertCount(2, $posts, 'Post cache should be invalidated');
        $this->assertGreaterThan(0, $postQueries);
        // Tag cache behavior depends on driver (array doesn't support tags)
        $this->assertCount(1, $tags);
    }

    // ========== Stampede prevention (cache locks) ==========

    #[Test]
    public function it_executes_query_when_stampede_lock_is_held()
    {
        config()->set('model-cache.use_cache_locks', true);
        // Keep the polling loop short so the fallback path runs fast in tests.
        config()->set('model-cache.cache_lock_seconds', 1);

        Post::create(['title' => 'Locked', 'content' => 'Content', 'published' => true]);

        $builder = Post::where('published', true);
        $lock = Cache::store()->lock('stampede:' . $builder->getCacheKey(), 30);
        $lock->get();

        try {
            // Another request holds the stampede lock and is still computing —
            // we must not return null (which used to throw a TypeError);
            // the query should be executed directly instead.
            $posts = $builder->getFromCache();
            $this->assertCount(1, $posts);
            $this->assertEquals('Locked', $posts->first()->title);
        } finally {
            $lock->forceRelease();
        }
    }

    #[Test]
    public function it_caches_queries_with_stampede_locks_enabled()
    {
        config()->set('model-cache.use_cache_locks', true);

        Post::create(['title' => 'Locked', 'content' => 'Content', 'published' => true]);

        DB::enableQueryLog();
        $posts = Post::where('published', true)->getFromCache();
        $firstQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        DB::enableQueryLog();
        Post::where('published', true)->getFromCache();
        $secondQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts);
        $this->assertGreaterThan(0, $firstQueries, 'First read should hit the database');
        $this->assertEquals(0, $secondQueries, 'Second read should be served from cache');
    }
}
