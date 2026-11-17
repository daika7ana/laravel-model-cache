<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithoutCache;
use Daika7ana\LaravelModelCache\Tests\TestCase;

class ExistsAndRandomOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Post::create(['title' => 'First', 'content' => 'a', 'published' => true, 'views' => 10]);
        Post::create(['title' => 'Second', 'content' => 'b', 'published' => false, 'views' => 20]);
    }

    #[Test]
    public function it_caches_exists_results()
    {
        $this->assertTrue(Post::where('title', 'First')->exists());

        DB::enableQueryLog();
        $this->assertTrue(Post::where('title', 'First')->exists());
        $this->assertCount(0, DB::getQueryLog(), 'Second exists() should be served from cache');
        DB::disableQueryLog();
    }

    #[Test]
    public function it_caches_a_false_exists_result()
    {
        $this->assertFalse(Post::where('title', 'Missing')->exists());

        DB::enableQueryLog();
        $this->assertFalse(Post::where('title', 'Missing')->exists());
        $this->assertCount(0, DB::getQueryLog(), 'A cached false must still be a cache hit');
        DB::disableQueryLog();
    }

    #[Test]
    public function doesnt_exist_uses_the_cached_exists_result()
    {
        $this->assertTrue(Post::where('title', 'First')->exists());

        DB::enableQueryLog();
        $this->assertFalse(Post::where('title', 'First')->doesntExist());
        $this->assertCount(0, DB::getQueryLog(), 'doesntExist() should reuse the cached exists() entry');
        DB::disableQueryLog();
    }

    #[Test]
    public function it_invalidates_exists_cache_on_write()
    {
        $this->assertTrue(Post::where('title', 'First')->exists());

        Post::where('title', 'First')->delete();

        $this->assertFalse(Post::where('title', 'First')->exists());
    }

    #[Test]
    public function exists_has_a_key_distinct_from_get()
    {
        $rows = Post::where('published', true)->get();
        $exists = Post::where('published', true)->exists();

        $this->assertCount(1, $rows);
        $this->assertTrue($exists);
        $this->assertNotSame(
            Post::where('published', true)->getCacheKey(),
            Post::where('published', true)->getCacheKey(['exists'], false),
        );
    }

    #[Test]
    public function exists_bypasses_cache_when_disabled()
    {
        config()->set('model-cache.enabled', false);

        Post::where('title', 'First')->exists();

        DB::enableQueryLog();
        Post::where('title', 'First')->exists();
        $this->assertCount(1, DB::getQueryLog(), 'Disabled caching should hit the database');
        DB::disableQueryLog();
    }

    #[Test]
    public function in_random_order_queries_bypass_the_cache()
    {
        Post::where('published', true)->inRandomOrder()->get();

        // Mutate via a non-cached model: a cached read would serve stale data.
        PostWithoutCache::where('published', true)->update(['title' => 'Changed behind cache']);

        $titles = Post::where('published', true)->inRandomOrder()->get()->pluck('title')->all();

        $this->assertSame(['Changed behind cache'], $titles, 'inRandomOrder() must read the DB, never the cache');
    }

    #[Test]
    public function in_random_order_works_for_exists_and_count()
    {
        Post::where('published', true)->inRandomOrder()->exists();
        Post::where('published', true)->inRandomOrder()->count();

        PostWithoutCache::where('published', true)->update(['title' => 'Changed behind cache']);

        $this->assertTrue(Post::where('published', true)->inRandomOrder()->exists());
        $this->assertSame('Changed behind cache', Post::where('published', true)->inRandomOrder()->first()->title);
    }

    #[Test]
    public function an_in_random_order_eager_load_does_not_freeze_the_parent_result()
    {
        $first = Post::with(['comments' => fn($q) => $q->inRandomOrder()])->where('title', 'First')->first();
        $this->assertNotNull($first);

        PostWithoutCache::where('title', 'First')->update(['content' => 'Changed behind cache']);

        $second = Post::with(['comments' => fn($q) => $q->inRandomOrder()])->where('title', 'First')->first();

        $this->assertSame('Changed behind cache', $second->content, 'An eager-load random order must not freeze the parent query');
    }
}
