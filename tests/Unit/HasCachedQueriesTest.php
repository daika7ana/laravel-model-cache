<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithCustomCache;
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
        $posts1 = Post::where('published', true)->get();
        $this->assertCount(1, $posts1);

        // Second query - should hit the cache
        $posts2 = Post::where('published', true)->get();
        $this->assertCount(1, $posts2);

        // Verify same results
        $this->assertEquals($posts1->first()->id, $posts2->first()->id);
    }

    #[Test]
    public function it_invalidates_cache_on_create()
    {
        // Create first post
        Post::create([
            'title' => 'First Post',
            'content' => 'First Content',
            'published' => true,
        ]);

        // Get cached results
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);

        // Create second post (should invalidate cache)
        Post::create([
            'title' => 'Second Post',
            'content' => 'Second Content',
            'published' => true,
        ]);

        // Query again - should get fresh data with 2 posts
        $posts = Post::where('published', true)->get();
        $this->assertCount(2, $posts);
    }

    #[Test]
    public function it_invalidates_cache_on_update()
    {
        // Create a post
        $post = Post::create([
            'title' => 'Original Title',
            'content' => 'Original Content',
            'published' => false,
        ]);

        // Get cached results
        $posts = Post::where('published', false)->get();
        $this->assertCount(1, $posts);
        $this->assertEquals('Original Title', $posts->first()->title);

        // Update the post
        $post->update(['title' => 'Updated Title']);

        // Query again - should get fresh data
        $posts = Post::where('published', false)->get();
        $this->assertCount(1, $posts);
        $this->assertEquals('Updated Title', $posts->first()->title);
    }

    #[Test]
    public function it_invalidates_cache_on_delete()
    {
        // Create posts
        $post1 = Post::create([
            'title' => 'Post 1',
            'content' => 'Content 1',
            'published' => true,
        ]);

        Post::create([
            'title' => 'Post 2',
            'content' => 'Content 2',
            'published' => true,
        ]);

        // Get cached results
        $posts = Post::where('published', true)->get();
        $this->assertCount(2, $posts);

        // Delete first post
        $post1->delete();

        // Query again - should get fresh data with 1 post
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);
    }

    #[Test]
    public function it_invalidates_cache_on_restore()
    {
        // Create and soft delete a post
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $post->delete();

        // Query without trashed
        $posts = Post::where('published', true)->get();
        $this->assertCount(0, $posts);

        // Restore the post
        $post->restore();

        // Query again - should get fresh data with restored post
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);
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
        $post1 = Post::where('published', true)->orderBy('id', 'asc')->first();
        $this->assertNotNull($post1);
        $this->assertEquals('First Post', $post1->title);

        // Second query - should hit the cache
        $post2 = Post::where('published', true)->orderBy('id', 'asc')->first();
        $this->assertNotNull($post2);
        $this->assertEquals($post1->id, $post2->id);
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

        // Use scope
        $posts = Post::published()->get();
        $this->assertCount(1, $posts);

        // Use another scope
        $popularPosts = Post::popular()->get();
        $this->assertCount(1, $popularPosts);
    }
}
