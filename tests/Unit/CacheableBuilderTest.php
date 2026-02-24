<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\TestCase;

class CacheableBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

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

        // Mass update
        Post::where('published', false)->update(['published' => true]);

        // Query again - should reflect the update
        $unpublishedPosts = Post::where('published', false)->get();
        $publishedPosts = Post::where('published', true)->get();

        $this->assertCount(0, $unpublishedPosts);
        $this->assertCount(2, $publishedPosts);
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

        // Mass delete
        Post::where('published', true)->delete();

        // Query again - should reflect the deletion
        $posts = Post::where('published', true)->get();
        $this->assertCount(0, $posts);
    }

    #[Test]
    public function it_can_bypass_cache_with_fresh_method()
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
        \DB::table('posts')->where('id', $post->id)->update(['title' => 'Updated Title']);

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
        // Cache an empty query
        $posts = Post::where('published', true)->get();
        $this->assertCount(0, $posts);

        // Insert new record using query builder
        Post::query()->insert([
            'title' => 'Inserted Post',
            'content' => 'Content',
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query again - should reflect the insertion
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);
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

        // Cache the query
        $cachedPost = Post::find($post->id);
        $this->assertEquals(100, $cachedPost->views);

        // Increment views
        Post::where('id', $post->id)->increment('views', 50);

        // Query again - should reflect the increment
        $updatedPost = Post::find($post->id);
        $this->assertEquals(150, $updatedPost->views);
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

        // Cache the query
        $cachedPost = Post::find($post->id);
        $this->assertEquals(100, $cachedPost->views);

        // Decrement views
        Post::where('id', $post->id)->decrement('views', 30);

        // Query again - should reflect the decrement
        $updatedPost = Post::find($post->id);
        $this->assertEquals(70, $updatedPost->views);
    }
}
