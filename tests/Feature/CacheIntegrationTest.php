<?php

namespace YMigVal\LaravelModelCache\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Post;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithGlobalScope;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Tag;
use YMigVal\LaravelModelCache\Tests\TestCase;

class CacheIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    #[Test]
    public function it_handles_complex_queries_with_cache()
    {
        // Create test data
        for ($i = 1; $i <= 10; $i++) {
            Post::create([
                'title' => "Post $i",
                'content' => "Content $i",
                'published' => $i % 2 === 0,
                'views' => $i * 100,
            ]);
        }

        // Complex query with multiple conditions
        $posts = Post::where('published', true)
            ->where('views', '>', 200)
            ->orderBy('views', 'desc')
            ->get();

        $this->assertCount(4, $posts); // Posts 4, 6, 8, 10

        // Same query again - should be cached
        $cachedPosts = Post::where('published', true)
            ->where('views', '>', 200)
            ->orderBy('views', 'desc')
            ->get();

        $this->assertCount(4, $cachedPosts);
        $this->assertEquals($posts->first()->id, $cachedPosts->first()->id);
    }

    #[Test]
    public function it_handles_eager_loading_with_cache()
    {
        // Create test data
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        $post->tags()->attach([$tag1->id, $tag2->id]);

        // Query with eager loading
        $posts = Post::with('tags')->where('published', true)->get();
        $this->assertCount(1, $posts);
        $this->assertCount(2, $posts->first()->tags);

        // Same query again - should be cached
        $cachedPosts = Post::with('tags')->where('published', true)->get();
        $this->assertCount(1, $cachedPosts);
        $this->assertCount(2, $cachedPosts->first()->tags);
    }

    #[Test]
    public function it_handles_pagination_with_cache()
    {
        // Create test data
        for ($i = 1; $i <= 20; $i++) {
            Post::create([
                'title' => "Post $i",
                'content' => "Content $i",
                'published' => true,
            ]);
        }

        // First page
        $page1 = Post::where('published', true)->paginate(10);
        $this->assertCount(10, $page1);

        // Second page
        $page2 = Post::where('published', true)->paginate(10, ['*'], 'page', 2);
        $this->assertCount(10, $page2);

        // Different pages should have different data
        $this->assertNotEquals($page1->first()->id, $page2->first()->id);
    }

    #[Test]
    public function it_handles_aggregate_functions_with_cache()
    {
        // Create test data
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true, 'views' => 100]);
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'published' => true, 'views' => 200]);
        Post::create(['title' => 'Post 3', 'content' => 'Content', 'published' => true, 'views' => 300]);

        // Count
        $count = Post::where('published', true)->count();
        $this->assertEquals(3, $count);

        // Sum
        $sum = Post::where('published', true)->sum('views');
        $this->assertEquals(600, $sum);

        // Average
        $avg = Post::where('published', true)->avg('views');
        $this->assertEquals(200, $avg);

        // Max
        $max = Post::where('published', true)->max('views');
        $this->assertEquals(300, $max);

        // Min
        $min = Post::where('published', true)->min('views');
        $this->assertEquals(100, $min);
    }

    #[Test]
    public function it_handles_soft_deletes_with_cache()
    {
        // Create and soft delete posts
        $post1 = Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);
        $post2 = Post::create(['title' => 'Post 2', 'content' => 'Content', 'published' => true]);

        $post1->delete();

        // Query without trashed
        $posts = Post::where('published', true)->get();
        $this->assertCount(1, $posts);

        // Query with trashed
        $postsWithTrashed = Post::withTrashed()->where('published', true)->get();
        $this->assertCount(2, $postsWithTrashed);

        // Query only trashed
        $onlyTrashed = Post::onlyTrashed()->where('published', true)->get();
        $this->assertCount(1, $onlyTrashed);
    }

    #[Test]
    public function it_handles_multiple_models_with_independent_caches()
    {
        // Create test data for posts
        Post::create(['title' => 'Post 1', 'content' => 'Content', 'published' => true]);

        // Create test data for tags
        Tag::create(['name' => 'Tag 1']);

        // Cache queries for both models
        $posts = Post::where('published', true)->get();
        $tags = Tag::all();

        $this->assertCount(1, $posts);
        $this->assertCount(1, $tags);

        // Add new post - should only invalidate Post cache
        Post::create(['title' => 'Post 2', 'content' => 'Content', 'published' => true]);

        // Posts should be updated
        $posts = Post::where('published', true)->get();
        $this->assertCount(2, $posts);

        // Tags cache should still be valid
        $tags = Tag::all();
        $this->assertCount(1, $tags);
    }

    #[Test]
    public function it_handles_chunk_queries()
    {
        // Create test data
        for ($i = 1; $i <= 50; $i++) {
            Post::create([
                'title' => "Post $i",
                'content' => "Content $i",
                'published' => true,
            ]);
        }

        $count = 0;
        Post::where('published', true)->chunk(10, function ($posts) use (&$count) {
            $count += $posts->count();
        });

        $this->assertEquals(50, $count);
    }

    #[Test]
    public function it_handles_cursor_queries()
    {
        // Create test data
        for ($i = 1; $i <= 20; $i++) {
            Post::create([
                'title' => "Post $i",
                'content' => "Content $i",
                'published' => true,
            ]);
        }

        $count = 0;
        foreach (Post::where('published', true)->cursor() as $post) {
            $count++;
        }

        $this->assertEquals(20, $count);
    }

    #[Test]
    public function it_handles_find_or_fail_with_cache()
    {
        // Create test data
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Find the post
        $foundPost = Post::findOrFail($post->id);
        $this->assertEquals('Test Post', $foundPost->title);

        // Should be cached
        $cachedPost = Post::findOrFail($post->id);
        $this->assertEquals($foundPost->id, $cachedPost->id);
    }

    #[Test]
    public function it_handles_local_scopes_with_cache_invalidation()
    {
        Post::create([
            'title' => 'Published 1',
            'content' => 'Content',
            'published' => true,
            'views' => 1200,
        ]);

        Post::create([
            'title' => 'Draft 1',
            'content' => 'Content',
            'published' => false,
            'views' => 2000,
        ]);

        $publishedPosts = Post::published()->get();
        $this->assertCount(1, $publishedPosts);

        $popularPosts = Post::popular()->get();
        $this->assertCount(2, $popularPosts);

        $cachedPublishedPosts = Post::published()->get();
        $cachedPopularPosts = Post::popular()->get();
        $this->assertCount(1, $cachedPublishedPosts);
        $this->assertCount(2, $cachedPopularPosts);

        Post::create([
            'title' => 'Published 2',
            'content' => 'Content',
            'published' => true,
            'views' => 1500,
        ]);

        $this->assertCount(2, Post::published()->get());
        $this->assertCount(3, Post::popular()->get());
    }

    #[Test]
    public function it_handles_without_global_scope_queries_with_cache()
    {
        PostWithGlobalScope::create([
            'title' => 'Scoped Published',
            'content' => 'Content',
            'published' => true,
        ]);

        PostWithGlobalScope::create([
            'title' => 'Scoped Draft',
            'content' => 'Content',
            'published' => false,
        ]);

        $scopedPosts = PostWithGlobalScope::query()->get();
        $this->assertCount(1, $scopedPosts);

        $unscopedPosts = PostWithGlobalScope::withoutGlobalScope('published_only')->get();
        $this->assertCount(2, $unscopedPosts);

        $cachedScopedPosts = PostWithGlobalScope::query()->get();
        $cachedUnscopedPosts = PostWithGlobalScope::withoutGlobalScope('published_only')->get();
        $this->assertCount(1, $cachedScopedPosts);
        $this->assertCount(2, $cachedUnscopedPosts);
    }

    #[Test]
    public function it_handles_without_global_scopes_queries_with_cache_invalidation()
    {
        PostWithGlobalScope::create([
            'title' => 'Published A',
            'content' => 'Content',
            'published' => true,
        ]);

        PostWithGlobalScope::create([
            'title' => 'Draft A',
            'content' => 'Content',
            'published' => false,
        ]);

        $allPosts = PostWithGlobalScope::withoutGlobalScopes()->get();
        $this->assertCount(2, $allPosts);

        $cachedAllPosts = PostWithGlobalScope::withoutGlobalScopes()->get();
        $this->assertCount(2, $cachedAllPosts);

        PostWithGlobalScope::create([
            'title' => 'Draft B',
            'content' => 'Content',
            'published' => false,
        ]);

        $this->assertCount(3, PostWithGlobalScope::withoutGlobalScopes()->get());
        $this->assertCount(1, PostWithGlobalScope::query()->get());
    }
}
