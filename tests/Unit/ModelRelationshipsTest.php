<?php

namespace YMigVal\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Author;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Comment;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use YMigVal\LaravelModelCache\Tests\Fixtures\Models\Tag;
use YMigVal\LaravelModelCache\Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    #[Test]
    public function it_invalidates_cache_on_attach()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        // Cache the relationship query
        $tags = $post->tags;
        $this->assertCount(0, $tags);

        // Attach tags
        $post->tags()->attach([$tag1->id, $tag2->id]);

        // Query again - should reflect the attachment
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(2, $tags);
    }

    #[Test]
    public function it_invalidates_cache_on_detach()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        // Attach tags
        $post->tags()->attach([$tag1->id, $tag2->id]);

        // Cache the relationship query
        $tags = $post->tags;
        $this->assertCount(2, $tags);

        // Detach one tag
        $post->tags()->detach($tag1->id);

        // Query again - should reflect the detachment
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(1, $tags);
        $this->assertEquals('Tag 2', $tags->first()->name);
    }

    #[Test]
    public function it_invalidates_cache_on_sync()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);
        $tag3 = Tag::create(['name' => 'Tag 3']);

        // Attach initial tags
        $post->tags()->attach([$tag1->id, $tag2->id]);

        // Cache the relationship query
        $tags = $post->tags;
        $this->assertCount(2, $tags);

        // Sync with different tags
        $post->tags()->sync([$tag2->id, $tag3->id]);

        // Query again - should reflect the sync
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(2, $tags);

        $tagNames = $tags->pluck('name')->toArray();
        $this->assertContains('Tag 2', $tagNames);
        $this->assertContains('Tag 3', $tagNames);
        $this->assertNotContains('Tag 1', $tagNames);
    }

    #[Test]
    public function it_uses_attach_relationship_and_flush_cache_method()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag = Tag::create(['name' => 'Test Tag']);

        // Use the helper method
        $post->attachRelationshipAndFlushCache('tags', $tag->id);

        // Query - should reflect the attachment
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(1, $tags);
        $this->assertEquals('Test Tag', $tags->first()->name);
    }

    #[Test]
    public function it_uses_detach_relationship_and_flush_cache_method()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag = Tag::create(['name' => 'Test Tag']);
        $post->tags()->attach($tag->id);

        // Verify attachment
        $this->assertCount(1, $post->tags);

        // Use the helper method to detach
        $post->detachRelationshipAndFlushCache('tags', $tag->id);

        // Query - should reflect the detachment
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(0, $tags);
    }

    #[Test]
    public function it_uses_sync_relationship_and_flush_cache_method()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        // Attach initial tag
        $post->tags()->attach($tag1->id);

        // Use the helper method to sync
        $post->syncRelationshipAndFlushCache('tags', [$tag2->id]);

        // Query - should reflect the sync
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(1, $tags);
        $this->assertEquals('Tag 2', $tags->first()->name);
    }

    #[Test]
    public function it_handles_sync_without_detaching()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        // Attach initial tag
        $post->tags()->attach($tag1->id);
        $this->assertCount(1, $post->fresh()->tags);

        // Sync without detaching
        $post->tags()->syncWithoutDetaching([$tag2->id]);

        // Query - should have both tags
        $post->refresh();
        $tags = $post->tags;
        $this->assertCount(2, $tags);
    }

    #[Test]
    public function it_handles_update_existing_pivot()
    {
        // Create test data with pivot attributes
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $tag = Tag::create(['name' => 'Test Tag']);

        // Attach with pivot data
        $post->tags()->attach($tag->id);

        // Update existing pivot
        $post->tags()->updateExistingPivot($tag->id, [
            'created_at' => now()->subDay(),
        ]);

        // Query - should reflect the pivot update
        $post->refresh();
        $pivotTag = $post->tags()->wherePivot('tag_id', $tag->id)->first();
        $this->assertNotNull($pivotTag);
    }

    // HasMany Relationship Tests (Comments)

    #[Test]
    public function it_creates_has_many_relationship()
    {
        // Create test data
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Create comments
        Comment::create([
            'post_id' => $post->id,
            'author' => 'John Doe',
            'body' => 'Great post!',
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Jane Smith',
            'body' => 'Thanks for sharing!',
        ]);

        // Test relationship
        $comments = $post->comments;
        $this->assertCount(2, $comments);
        $this->assertEquals('John Doe', $comments->first()->author);
    }

    #[Test]
    public function it_invalidates_cache_on_has_many_create()
    {
        // Create post
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Cache empty comments
        $comments = $post->comments;
        $this->assertCount(0, $comments);

        // Create comment
        $post->comments()->create([
            'author' => 'John Doe',
            'body' => 'Great post!',
        ]);

        // Query again - should reflect new comment
        $post->refresh();
        $comments = $post->comments;
        $this->assertCount(1, $comments);
    }

    #[Test]
    public function it_invalidates_cache_on_has_many_delete()
    {
        // Create post and comments
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $comment1 = Comment::create([
            'post_id' => $post->id,
            'author' => 'John Doe',
            'body' => 'First comment',
        ]);

        $comment2 = Comment::create([
            'post_id' => $post->id,
            'author' => 'Jane Smith',
            'body' => 'Second comment',
        ]);

        // Cache comments
        $comments = $post->comments;
        $this->assertCount(2, $comments);

        // Delete comment
        $comment1->delete();

        // Query again - should reflect deletion
        $post->refresh();
        $comments = $post->comments;
        $this->assertCount(1, $comments);
        $this->assertEquals('Jane Smith', $comments->first()->author);
    }

    #[Test]
    public function it_invalidates_cache_on_has_many_via_relationship()
    {
        // Create post
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Cache comments
        $comments = $post->comments;
        $this->assertCount(0, $comments);

        // Add comment via relationship
        $post->comments()->create([
            'author' => 'John Doe',
            'body' => 'Great post!',
        ]);

        // Query again
        $post->refresh();
        $comments = $post->comments;
        $this->assertCount(1, $comments);
    }

    // BelongsTo Relationship Tests (Author)

    #[Test]
    public function it_creates_belongs_to_relationship_with_author()
    {
        // Create author
        $author = Author::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Create post
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        // Test relationship
        $retrievedAuthor = $post->author;
        $this->assertNotNull($retrievedAuthor);
        $this->assertEquals('John Doe', $retrievedAuthor->name);
    }

    #[Test]
    public function it_invalidates_cache_on_belongs_to_update()
    {
        // Create author
        $author1 = Author::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $author2 = Author::create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        // Create post
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
            'author_id' => $author1->id,
        ]);

        // Cache author relationship
        $cachedAuthor = $post->author;
        $this->assertEquals('John Doe', $cachedAuthor->name);

        // Update post's author
        $post->update(['author_id' => $author2->id]);

        // Query again - should reflect new author
        $post->refresh();
        $newAuthor = $post->author;
        $this->assertEquals('Jane Smith', $newAuthor->name);
    }

    #[Test]
    public function it_creates_has_many_relationship_from_author()
    {
        // Create author
        $author = Author::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Create posts
        PostWithRelationships::create([
            'title' => 'First Post',
            'content' => 'Content 1',
            'published' => true,
            'author_id' => $author->id,
        ]);

        PostWithRelationships::create([
            'title' => 'Second Post',
            'content' => 'Content 2',
            'published' => true,
            'author_id' => $author->id,
        ]);

        // Test relationship
        $posts = $author->posts;
        $this->assertCount(2, $posts);
    }

    #[Test]
    public function it_invalidates_cache_on_author_posts_create()
    {
        // Create author
        $author = Author::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Cache empty posts
        $posts = $author->posts;
        $this->assertCount(0, $posts);

        // Create post
        PostWithRelationships::create([
            'title' => 'New Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        // Query again
        $author->refresh();
        $posts = $author->posts;
        $this->assertCount(1, $posts);
    }

    // Comment -> Post BelongsTo Tests

    #[Test]
    public function it_creates_belongs_to_relationship_in_comment()
    {
        // Create post
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        // Create comment
        $comment = Comment::create([
            'post_id' => $post->id,
            'author' => 'John Doe',
            'body' => 'Great post!',
        ]);

        // Test relationship
        $retrievedPost = $comment->post;
        $this->assertNotNull($retrievedPost);
        $this->assertEquals('Test Post', $retrievedPost->title);
    }

    #[Test]
    public function it_invalidates_cache_on_comment_post_change()
    {
        // Create posts
        $post1 = PostWithRelationships::create([
            'title' => 'First Post',
            'content' => 'Content 1',
            'published' => true,
        ]);

        $post2 = PostWithRelationships::create([
            'title' => 'Second Post',
            'content' => 'Content 2',
            'published' => true,
        ]);

        // Create comment for first post
        $comment = Comment::create([
            'post_id' => $post1->id,
            'author' => 'John Doe',
            'body' => 'Great post!',
        ]);

        // Cache relationship
        $cachedPost = $comment->post;
        $this->assertEquals('First Post', $cachedPost->title);

        // Update comment to point to second post
        $comment->update(['post_id' => $post2->id]);

        // Query again
        $comment->refresh();
        $newPost = $comment->post;
        $this->assertEquals('Second Post', $newPost->title);
    }

    #[Test]
    public function it_creates_polymorphic_relationships_for_post_and_tag()
    {
        $post = PostWithRelationships::create([
            'title' => 'Polymorphic Post',
            'content' => 'Polymorphic content',
            'published' => true,
        ]);

        $tag = Tag::create(['name' => 'Polymorphic Tag']);

        $postComment = $post->polymorphicComments()->create([
            'post_id' => $post->id,
            'author' => 'Alice',
            'body' => 'Comment for post',
        ]);

        $tagComment = $tag->comments()->create([
            'post_id' => $post->id,
            'author' => 'Bob',
            'body' => 'Comment for tag',
        ]);

        $this->assertEquals(PostWithRelationships::class, $postComment->commentable_type);
        $this->assertEquals($post->id, $postComment->commentable_id);
        $this->assertEquals(Tag::class, $tagComment->commentable_type);
        $this->assertEquals($tag->id, $tagComment->commentable_id);

        $this->assertCount(1, $post->fresh()->polymorphicComments);
        $this->assertCount(1, $tag->fresh()->comments);
    }

    #[Test]
    public function it_invalidates_cache_on_polymorphic_relationship_create()
    {
        $post = PostWithRelationships::create([
            'title' => 'Cache Poly Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $comments = $post->polymorphicComments;
        $this->assertCount(0, $comments);

        $post->polymorphicComments()->create([
            'post_id' => $post->id,
            'author' => 'Alice',
            'body' => 'Polymorphic cache comment',
        ]);

        $this->assertCount(1, $post->fresh()->polymorphicComments);
    }

    #[Test]
    public function it_invalidates_cache_on_polymorphic_relationship_update()
    {
        $post = PostWithRelationships::create([
            'title' => 'Cache Poly Update Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $comment = $post->polymorphicComments()->create([
            'post_id' => $post->id,
            'author' => 'Alice',
            'body' => 'Original body',
        ]);

        $cachedBody = $post->polymorphicComments->first()->body;
        $this->assertEquals('Original body', $cachedBody);

        $comment->update(['body' => 'Updated body']);

        $this->assertEquals('Updated body', $post->fresh()->polymorphicComments->first()->body);
    }

    #[Test]
    public function it_invalidates_cache_on_polymorphic_relationship_delete()
    {
        $post = PostWithRelationships::create([
            'title' => 'Cache Poly Delete Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $comment = $post->polymorphicComments()->create([
            'post_id' => $post->id,
            'author' => 'Alice',
            'body' => 'Will be deleted',
        ]);

        $this->assertCount(1, $post->polymorphicComments);

        $comment->delete();

        $this->assertCount(0, $post->fresh()->polymorphicComments);
    }

    #[Test]
    public function it_creates_has_many_through_relationship_from_author_to_comments()
    {
        $author = Author::create([
            'name' => 'Through Author',
            'email' => 'through@author.test',
        ]);

        $post1 = PostWithRelationships::create([
            'title' => 'Through Post 1',
            'content' => 'Content 1',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $post2 = PostWithRelationships::create([
            'title' => 'Through Post 2',
            'content' => 'Content 2',
            'published' => true,
            'author_id' => $author->id,
        ]);

        Comment::create([
            'post_id' => $post1->id,
            'author' => 'User 1',
            'body' => 'Comment 1',
        ]);

        Comment::create([
            'post_id' => $post2->id,
            'author' => 'User 2',
            'body' => 'Comment 2',
        ]);

        $this->assertCount(2, $author->comments);
    }

    #[Test]
    public function it_invalidates_cache_on_has_many_through_create()
    {
        $author = Author::create([
            'name' => 'Through Cache Author',
            'email' => 'through-cache@author.test',
        ]);

        $post = PostWithRelationships::create([
            'title' => 'Through Cache Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $comments = $author->comments;
        $this->assertCount(0, $comments);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'New User',
            'body' => 'New through comment',
        ]);

        $this->assertCount(1, $author->fresh()->comments);
    }

    #[Test]
    public function it_invalidates_cache_on_has_many_through_update()
    {
        $author = Author::create([
            'name' => 'Through Update Author',
            'email' => 'through-update@author.test',
        ]);

        $post = PostWithRelationships::create([
            'title' => 'Through Update Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $comment = Comment::create([
            'post_id' => $post->id,
            'author' => 'User',
            'body' => 'Before update',
        ]);

        $this->assertEquals('Before update', $author->comments->first()->body);

        $comment->update(['body' => 'After update']);

        $this->assertEquals('After update', $author->fresh()->comments->first()->body);
    }

    #[Test]
    public function it_invalidates_cache_on_has_many_through_delete()
    {
        $author = Author::create([
            'name' => 'Through Delete Author',
            'email' => 'through-delete@author.test',
        ]);

        $post = PostWithRelationships::create([
            'title' => 'Through Delete Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $comment = Comment::create([
            'post_id' => $post->id,
            'author' => 'User',
            'body' => 'Delete me',
        ]);

        $this->assertCount(1, $author->comments);

        $comment->delete();

        $this->assertCount(0, $author->fresh()->comments);
    }

    #[Test]
    public function it_creates_has_one_through_relationship_from_author_to_first_comment()
    {
        $author = Author::create([
            'name' => 'One Through Author',
            'email' => 'one-through@author.test',
        ]);

        $post = PostWithRelationships::create([
            'title' => 'One Through Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'First User',
            'body' => 'First through comment',
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Second User',
            'body' => 'Second through comment',
        ]);

        $firstComment = $author->firstComment;
        $this->assertNotNull($firstComment);
        $this->assertEquals('First User', $firstComment->author);
    }

    #[Test]
    public function it_creates_has_one_of_many_relationship_for_latest_comment()
    {
        $post = PostWithRelationships::create([
            'title' => 'One Of Many Post',
            'content' => 'Content',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Early User',
            'body' => 'Early comment',
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Latest User',
            'body' => 'Latest comment',
        ]);

        $latestComment = $post->latestComment;
        $this->assertNotNull($latestComment);
        $this->assertEquals('Latest User', $latestComment->author);
    }

    #[Test]
    public function it_creates_direct_latest_of_many_relationship_for_latest_comment()
    {
        $post = PostWithRelationships::create([
            'title' => 'Direct Latest Of Many Post',
            'content' => 'Content',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Older User',
            'body' => 'Older comment',
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Newest User',
            'body' => 'Newest comment',
        ]);

        $latestComment = $post->latestCommentDirect;
        $this->assertNotNull($latestComment);
        $this->assertEquals('Newest User', $latestComment->author);
    }

    #[Test]
    public function it_invalidates_cache_on_has_one_of_many_after_new_comment()
    {
        $post = PostWithRelationships::create([
            'title' => 'One Of Many Cache Post',
            'content' => 'Content',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Initial Latest',
            'body' => 'Initial latest comment',
        ]);

        $cachedLatest = $post->latestComment;
        $this->assertEquals('Initial Latest', $cachedLatest->author);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'New Latest',
            'body' => 'New latest comment',
        ]);

        $this->assertEquals('New Latest', $post->fresh()->latestComment->author);
    }

    #[Test]
    public function it_invalidates_cache_on_has_one_of_many_after_delete()
    {
        $post = PostWithRelationships::create([
            'title' => 'One Of Many Delete Cache Post',
            'content' => 'Content',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Previous Latest',
            'body' => 'Previous latest comment',
        ]);

        $latestComment = Comment::create([
            'post_id' => $post->id,
            'author' => 'Current Latest',
            'body' => 'Current latest comment',
        ]);

        $cachedLatest = $post->latestCommentDirect;
        $this->assertEquals('Current Latest', $cachedLatest->author);

        $latestComment->delete();

        $this->assertEquals('Previous Latest', $post->fresh()->latestCommentDirect->author);
    }

    #[Test]
    public function it_invalidates_cache_on_has_one_of_many_after_update()
    {
        $post = PostWithRelationships::create([
            'title' => 'One Of Many Update Cache Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $olderComment = Comment::create([
            'post_id' => $post->id,
            'author' => 'Will Become Latest',
            'body' => 'Old body',
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Initially Latest',
            'body' => 'Latest body',
        ]);

        $cachedLatest = $post->mostRecentlyUpdatedComment;
        $this->assertEquals('Initially Latest', $cachedLatest->author);

        $futureUpdatedAt = now()->addMinutes(5);
        $olderComment->forceFill([
            'body' => 'Updated to become latest',
            'updated_at' => $futureUpdatedAt,
        ])->save();

        $olderComment->refresh();
        $this->assertEquals($futureUpdatedAt->timestamp, $olderComment->updated_at->timestamp);

        $this->assertEquals('Will Become Latest', $post->fresh()->mostRecentlyUpdatedComment->author);
    }
}
