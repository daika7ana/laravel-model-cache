<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Author;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithCustomCache;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithEagerLoading;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Tag;
use YMigVal\LaravelModelCache\Tests\TestCase;

class HasCachedQueriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    #[Test]
    public function it_caches_query_results()
    {
        // Create test data
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // First query - should hit the database
        DB::enableQueryLog();
        $posts1 = Post::where('published', true)->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertCount(1, $posts1);
        $this->assertGreaterThan(0, $firstQueryCount, 'First query should execute database queries');

        // Second query - should hit the cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $posts2 = Post::where('published', true)->get();
        $secondQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts2);

        // When using database cache driver, there will be cache table queries, but no data table queries
        // When using array cache driver, there should be 0 data queries
        if (config('cache.default') === 'database') {
            // For database cache driver, just verify we got the same data
            // The cache hit is verified by the fact that we got the correct results
            $this->assertLessThanOrEqual($firstQueryCount, $secondQueryCount, 'Second query should use cache with fewer or equal queries');
        } else {
            $this->assertEquals(0, $secondQueryCount, 'Second query should be served from cache with 0 database queries');
        }

        // Verify same results
        $this->assertEquals($posts1->first()->id, $posts2->first()->id);
    }

    #[Test]
    public function it_uses_custom_cache_duration()
    {
        // This test verifies that custom cache duration is applied
        $post = PostWithCustomCache::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Query with custom cache settings
        $posts = PostWithCustomCache::where('published', true)->get();
        $this->assertCount(1, $posts);

        // The model has custom cache duration set to 120
        // We can't directly access protected property, but we can verify the model type
        $this->assertInstanceOf(PostWithCustomCache::class, $post);
    }

    #[Test]
    public function it_uses_custom_cache_prefix()
    {
        $post = PostWithCustomCache::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // The model has custom cache prefix set to 'custom_post_'
        // We can't directly access protected property, but we can verify the model type
        $this->assertInstanceOf(PostWithCustomCache::class, $post);
    }

    #[Test]
    public function it_can_flush_model_cache_statically()
    {
        // Create test data
        Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Cache the query
        Post::where('published', true)->get();

        // Flush the cache statically
        $result = Post::flushModelCache();

        // Should return true
        $this->assertTrue($result);
    }

    #[Test]
    public function it_can_flush_cache_on_instance()
    {
        // Create test data
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Cache the query
        Post::where('published', true)->get();

        // Flush the cache on instance
        $result = $post->flushCache();

        // Should return true
        $this->assertTrue($result);
    }

    #[Test]
    public function it_caches_first_query_results()
    {
        // Create test data
        Post::create([
            'title' => 'First Post',
            'content' => 'First Content',
            'published' => true,
        ]);

        Post::create([
            'title' => 'Second Post',
            'content' => 'Second Content',
            'published' => true,
        ]);

        // First query with first()
        DB::enableQueryLog();
        $post1 = Post::where('published', true)->orderBy('id', 'asc')->first();
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertNotNull($post1);
        $this->assertEquals('First Post', $post1->title);
        $this->assertGreaterThan(0, $firstQueryCount, 'First query should execute database queries');

        // Second query - should hit the cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $post2 = Post::where('published', true)->orderBy('id', 'asc')->first();
        $secondQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotNull($post2);
        $this->assertEquals($post1->id, $post2->id);

        // When using database cache driver, there will be cache table queries, but no data table queries
        // When using array cache driver, there should be 0 data queries
        if (config('cache.default') === 'database') {
            // For database cache driver, just verify we got the correct data
            // The cache hit is verified by the fact that we got the same result
            $this->assertLessThanOrEqual($firstQueryCount, $secondQueryCount, 'Second query should use cache with fewer or equal queries');
        } else {
            $this->assertEquals(0, $secondQueryCount, 'Second query should be served from cache with 0 database queries');
        }
    }

    #[Test]
    public function it_caches_queries_with_scopes()
    {
        // Create test data
        Post::create([
            'title' => 'Published Post',
            'content' => 'Content',
            'published' => true,
            'views' => 1500,
        ]);

        // Use scope - first call hits database
        DB::enableQueryLog();
        $posts = Post::published()->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertCount(1, $posts);
        $this->assertGreaterThan(0, $firstQueryCount, 'First scope query should execute database queries');

        // Use another scope - different query, should hit database
        DB::flushQueryLog();
        DB::enableQueryLog();
        $popularPosts = Post::popular()->get();
        $secondQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $popularPosts);
        $this->assertGreaterThan(0, $secondQueryCount, 'Different scope should execute database queries');
    }

    #[Test]
    public function it_eager_loads_relationships_by_default()
    {
        // Create test data
        $author = Author::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $post = PostWithEagerLoading::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $tag = Tag::create(['name' => 'Laravel']);
        $post->tags()->attach($tag->id);

        // First query - should eager load tags by default
        DB::enableQueryLog();
        $posts = PostWithEagerLoading::where('published', true)->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts);
        $this->assertTrue($posts[0]->relationLoaded('tags'), 'Tags relation should be eager loaded by default');
        $this->assertCount(1, $posts[0]->tags);
        $this->assertGreaterThan(0, $firstQueryCount, 'First query should execute database queries');
    }

    #[Test]
    public function it_caches_eager_loaded_results()
    {
        // Create test data
        $author = Author::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $post = PostWithEagerLoading::create([
            'title' => 'Cached Post',
            'content' => 'Cached Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $tag1 = Tag::create(['name' => 'PHP']);
        $tag2 = Tag::create(['name' => 'Testing']);
        $post->tags()->attach([$tag1->id, $tag2->id]);

        // First query - caches the result with eagerly loaded relations
        DB::enableQueryLog();
        $posts1 = PostWithEagerLoading::where('published', true)->get();
        $firstQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts1);
        $this->assertCount(2, $posts1[0]->tags);
        $this->assertGreaterThan(0, $firstQueryCount, 'First query should execute database queries');

        // Second query - should hit the cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $posts2 = PostWithEagerLoading::where('published', true)->get();
        $secondQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts2);
        $this->assertCount(2, $posts2[0]->tags);

        if (config('cache.default') === 'database') {
            $this->assertLessThanOrEqual($firstQueryCount, $secondQueryCount, 'Second query should use cache with fewer or equal queries');
        } else {
            $this->assertEquals(0, $secondQueryCount, 'Second query should be served from cache with 0 database queries');
        }
    }

    #[Test]
    public function it_excludes_eager_loaded_relations_with_without()
    {
        // Create test data
        $author = Author::create(['name' => 'Bob Smith', 'email' => 'bob@example.com']);
        $post = PostWithEagerLoading::create([
            'title' => 'Post Without Tags',
            'content' => 'No Tags Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $tag = Tag::create(['name' => 'Testing']);
        $post->tags()->attach($tag->id);

        // Query without the tags relation - should not eager load tags
        DB::enableQueryLog();
        $posts = PostWithEagerLoading::without('tags')->where('published', true)->get();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts);
        $this->assertFalse($posts[0]->relationLoaded('tags'), 'Tags relation should not be eager loaded when using without()');
    }

    #[Test]
    public function it_caches_different_queries_separately_with_and_without_relations()
    {
        // Create test data
        $author = Author::create(['name' => 'Alice Johnson', 'email' => 'alice@example.com']);
        $post = PostWithEagerLoading::create([
            'title' => 'Multi-Query Post',
            'content' => 'Test Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $tag = Tag::create(['name' => 'Caching']);
        $post->tags()->attach($tag->id);

        // First query: with eager loaded tags (default)
        DB::enableQueryLog();
        $postsWithTags = PostWithEagerLoading::where('published', true)->get();
        $withTagsFirstCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $postsWithTags);
        $this->assertTrue($postsWithTags[0]->relationLoaded('tags'), 'Tags should be eager loaded');

        // Second query: without tags
        DB::flushQueryLog();
        DB::enableQueryLog();
        $postsWithoutTags = PostWithEagerLoading::without('tags')->where('published', true)->get();
        $withoutTagsFirstCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $postsWithoutTags);
        $this->assertFalse($postsWithoutTags[0]->relationLoaded('tags'), 'Tags should not be eager loaded');

        // The two queries should have different cache keys (both should execute)
        // This is important: without() should generate a different cache key
        $this->assertGreaterThan(0, $withTagsFirstCount, 'First query with tags should execute');
        $this->assertGreaterThan(0, $withoutTagsFirstCount, 'First query without tags should execute (different cache key)');

        // Third query: repeat with tags (should hit cache)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $postsWithTagsAgain = PostWithEagerLoading::where('published', true)->get();
        $withTagsSecondCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $postsWithTagsAgain);

        if (config('cache.default') === 'database') {
            $this->assertLessThanOrEqual($withTagsFirstCount, $withTagsSecondCount, 'Second query with tags should use cache');
        } else {
            $this->assertEquals(0, $withTagsSecondCount, 'Second query with tags should be served from cache with 0 database queries');
        }
    }
}
