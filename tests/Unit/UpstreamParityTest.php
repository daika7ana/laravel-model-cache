<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\CacheableBuilder;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Author;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Comment;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\ProbeCacheableBuilder;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\ProbePost;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\ShortCachePost;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Tag;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\TenantAuthor;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\TenantPost;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\TenantState;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\ZeroCacheAuthor;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\ZeroCachePost;
use Daika7ana\LaravelModelCache\Tests\TestCase;

/**
 * Regression tests for upstream cache-correctness fixes.
 */
class UpstreamParityTest extends TestCase
{
    public static int $eagerConstraintRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Relation::morphMap(['tenant_author' => TenantAuthor::class], false);
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        TenantState::$tenant = null;
        TenantPost::$publishedFilter = null;

        parent::tearDown();
    }

    #[Test]
    public function it_keys_eager_loads_by_the_resolved_related_scope()
    {
        TenantState::$tenant = 'Alice';

        $alice = TenantAuthor::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = TenantPost::create([
            'title' => 'Scoped',
            'content' => 'Content',
            'published' => true,
            'author_id' => $alice->id,
        ]);

        $keyAlice = TenantPost::with('author')->where('id', $post->id)->getCacheKey();
        $first = TenantPost::with('author')->where('id', $post->id)->get();

        $this->assertSame('Alice', $first->first()->author->name);

        TenantState::$tenant = 'Bob';

        $keyBob = TenantPost::with('author')->where('id', $post->id)->getCacheKey();
        $second = TenantPost::with('author')->where('id', $post->id)->get();

        $this->assertNotSame($keyAlice, $keyBob);
        $author = $second->first()->author;
        $this->assertTrue($author === null || $author->name !== 'Alice', 'Bob must never see Alice under the scoped eager load');

        TenantState::$tenant = null;
    }

    #[Test]
    public function it_does_not_leak_after_query_mutations_into_plain_queries()
    {
        PostWithRelationships::create([
            'title' => 'Real',
            'content' => 'Content',
            'published' => true,
        ]);

        $mutate = fn($models) => $models->each(fn($model) => $model->title = 'Mutated');

        $mutated = PostWithRelationships::query()->afterQuery($mutate)->first();
        $this->assertSame('Mutated', $mutated->title);

        $plain = PostWithRelationships::query()->first();
        $this->assertSame('Real', $plain->title, 'A plain query must not read the afterQuery-mutated entry');

        $again = PostWithRelationships::query()->afterQuery($mutate)->first();
        $this->assertSame('Mutated', $again->title);
    }

    #[Test]
    public function it_flushes_only_the_written_models_cache()
    {
        Post::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        Tag::create(['name' => 'Original']);

        Post::where('published', true)->get();
        Tag::where('name', 'Original')->get();

        Tag::flushModelCache();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $posts = Post::where('published', true)->get();
        $postQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $tags = Tag::where('name', 'Original')->get();
        $tagQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1, $posts);
        $this->assertCount(1, $tags);
        $this->assertSame(0, $postQueries, 'A Tag flush must not invalidate Post cache entries');
        $this->assertGreaterThan(0, $tagQueries, 'A Tag flush must invalidate Tag cache entries');
    }

    #[Test]
    public function it_invalidates_eager_loaded_relations_when_the_related_model_is_written()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        $tag1 = Tag::create(['name' => 'First']);
        Tag::create(['name' => 'Second']);

        $post->tags()->attach($tag1->id);

        $cached = PostWithRelationships::with('tags')->where('id', $post->id)->get();
        $this->assertCount(1, $cached->first()->tags);

        $tag1->update(['name' => 'Renamed']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = PostWithRelationships::with('tags')->where('id', $post->id)->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'The related write must invalidate the eager-loaded snapshot');
        $this->assertSame('Renamed', $fresh->first()->tags->first()->name);
    }

    #[Test]
    public function it_invalidates_a_related_side_relation_query_after_a_pivot_write()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        $tag1 = Tag::create(['name' => 'First']);
        $tag2 = Tag::create(['name' => 'Second']);

        $post->tags()->attach($tag1->id);

        $this->assertCount(1, $post->tags()->get());

        $post->tags()->attach($tag2->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $tags = $post->tags()->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A pivot write must invalidate the relation query cache');
        $this->assertCount(2, $tags);
    }

    #[Test]
    public function it_handles_morph_to_eager_loads_without_cross_tenant_leaks()
    {
        TenantState::$tenant = 'Alice';

        $alice = TenantAuthor::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $comment = Comment::create([
            'post_id' => $post->id,
            'author' => 'Commenter',
            'body' => 'Hello',
            'commentable_type' => 'tenant_author',
            'commentable_id' => $alice->id,
        ]);

        $keyAlice = Comment::with('commentable')->where('id', $comment->id)->getCacheKey();
        $first = Comment::with('commentable')->where('id', $comment->id)->get();
        $this->assertSame('Alice', $first->first()->commentable->name);

        // Same tenant, same morph target: the root snapshot is cached.
        DB::flushQueryLog();
        DB::enableQueryLog();
        Comment::with('commentable')->where('id', $comment->id)->get();
        $cachedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(0, $cachedQueries, 'A resolved morphTo eager load must be cacheable');

        TenantState::$tenant = 'Bob';

        $keyBob = Comment::with('commentable')->where('id', $comment->id)->getCacheKey();
        $second = Comment::with('commentable')->where('id', $comment->id)->get();

        $this->assertNotSame($keyAlice, $keyBob);
        $relation = $second->first()->commentable;
        $this->assertTrue($relation === null || $relation->name !== 'Alice', 'Bob must never see Alice under a scoped morphTo');

        TenantState::$tenant = 'Alice';
        $alice->update(['email' => 'new@example.com']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $refreshed = Comment::with('commentable')->where('id', $comment->id)->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A morph target write must invalidate the parent snapshot');
        $this->assertSame('new@example.com', $refreshed->first()->commentable->email);
    }

    #[Test]
    public function it_bypasses_morph_to_without_a_morph_map()
    {
        Relation::morphMap([], false);
        TenantState::$tenant = 'Alice';

        $alice = TenantAuthor::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        Comment::create([
            'post_id' => $post->id,
            'author' => 'Commenter',
            'body' => 'Hello',
            'commentable_type' => TenantAuthor::class,
            'commentable_id' => $alice->id,
        ]);

        Comment::with('commentable')->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Comment::with('commentable')->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'Without a morph map, morphTo eager loads must bypass the cache');
    }

    #[Test]
    public function it_caches_morph_with_string_values()
    {
        TenantState::$tenant = 'Alice';

        $alice = TenantAuthor::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = TenantPost::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $alice->id,
        ]);
        $comment = Comment::create([
            'post_id' => $post->id,
            'author' => 'Commenter',
            'body' => 'Hello',
            'commentable_type' => 'tenant_author',
            'commentable_id' => $alice->id,
        ]);

        $make = fn() => Comment::with([
            'commentable' => fn($relation) => $relation->morphWith([TenantAuthor::class => 'filteredPosts']),
        ])->where('id', $comment->id);

        TenantPost::$publishedFilter = true;
        $keyPublished = $make()->getCacheKey();

        $first = $make()->get();
        $this->assertTrue($first->first()->commentable->relationLoaded('filteredPosts'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $make()->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(0, $queries, 'A string morphWith value must be cached');

        // Same string morphWith value, different nested relation state: the
        // target's nested query must be part of the key.
        TenantPost::$publishedFilter = false;
        $keyUnpublished = $make()->getCacheKey();

        $this->assertNotSame($keyPublished, $keyUnpublished, 'Nested state from a string morphWith value must be keyed');
    }

    #[Test]
    public function it_does_not_cache_morph_to_results_with_unmapped_types()
    {
        TenantState::$tenant = 'Alice';

        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        $target = TenantPost::create([
            'title' => 'Target',
            'content' => 'Content',
            'published' => true,
        ]);
        Comment::create([
            'post_id' => $post->id,
            'author' => 'Commenter',
            'body' => 'Hello',
            'commentable_type' => TenantPost::class,
            'commentable_id' => $target->id,
        ]);

        Comment::with('commentable')->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Comment::with('commentable')->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A partial morph map must not cache an unmapped target');
    }

    #[Test]
    public function it_caches_with_count_queries_and_invalidates_on_a_related_write()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'A',
            'body' => 'First',
        ]);

        PostWithRelationships::withCount('comments')->where('id', $post->id)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cached = PostWithRelationships::withCount('comments')->where('id', $post->id)->get();
        $cachedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $cachedQueries, 'withCount() queries must be cached');
        $this->assertSame(1, $cached->first()->comments_count);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'B',
            'body' => 'Second',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = PostWithRelationships::withCount('comments')->where('id', $post->id)->get();
        $freshQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $freshQueries, 'A related write must invalidate a withCount() query');
        $this->assertSame(2, $fresh->first()->comments_count);
    }

    #[Test]
    public function it_distinguishes_raw_columns_and_expression_bindings_in_cache_keys()
    {
        $keyA = Post::query()->getCacheKey([DB::raw('title as a')]);
        $keyB = Post::query()->getCacheKey([DB::raw('content as b')]);
        $this->assertNotSame($keyA, $keyB);

        $keyX = Post::whereRaw('title = ?', [DB::raw('x')])->getCacheKey();
        $keyY = Post::whereRaw('title = ?', [DB::raw('y')])->getCacheKey();
        $this->assertNotSame($keyX, $keyY);
    }

    #[Test]
    public function it_bypasses_the_root_query_when_an_eager_loaded_model_disables_caching()
    {
        $author = ZeroCacheAuthor::create(['name' => 'Zero', 'email' => 'zero@example.com']);
        $post = ZeroCachePost::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        ZeroCachePost::with('author')->where('id', $post->id)->get();
        $first = count(DB::getQueryLog());
        DB::flushQueryLog();
        ZeroCachePost::with('author')->where('id', $post->id)->get();
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan(0, $second, 'A related $cacheMinutes = 0 must prevent caching the root snapshot');
    }

    #[Test]
    public function it_uses_the_shortest_ttl_across_eager_loaded_models()
    {
        $builder = ShortCachePost::with('author');
        $builder->getCacheKey();

        $method = new \ReflectionMethod(CacheableBuilder::class, 'effectiveCacheMinutes');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($builder));
    }

    #[Test]
    public function it_invalidates_when_an_eager_load_constraint_filters_on_another_table()
    {
        $author = Author::create(['name' => 'Author', 'email' => 'author@example.com']);
        $post = Post::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $make = fn() => Author::with(['posts' => fn($query) => $query->whereHas('comments')])
            ->where('id', $author->id);

        $this->assertCount(0, $make()->get()->first()->posts);

        Comment::create(['post_id' => $post->id, 'author' => 'A', 'body' => 'x']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = $make()->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A write to a constraint-only table must invalidate the root snapshot');
        $this->assertCount(1, $fresh->first()->posts);
    }

    #[Test]
    public function it_bypasses_raw_subquery_columns()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        Comment::create(['post_id' => $post->id, 'author' => 'A', 'body' => 'First']);

        $make = fn() => PostWithRelationships::select('*', DB::raw('(select count(*) from comments) as comment_count'))
            ->where('id', $post->id);

        $this->assertSame(1, (int) $make()->get()->first()->comment_count);

        Comment::create(['post_id' => $post->id, 'author' => 'B', 'body' => 'Second']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = $make()->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A raw subquery column must bypass the cache');
        $this->assertSame(2, (int) $fresh->first()->comment_count);
    }

    #[Test]
    public function it_bypasses_raw_subquery_columns_passed_to_get()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        Comment::create(['post_id' => $post->id, 'author' => 'A', 'body' => 'First']);

        $columns = ['*', DB::raw('(select count(*) from comments) as comment_count')];
        $make = fn() => PostWithRelationships::query()->where('id', $post->id);

        $this->assertSame(1, (int) $make()->get($columns)->first()->comment_count);

        Comment::create(['post_id' => $post->id, 'author' => 'B', 'body' => 'Second']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = $make()->get($columns);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A raw subquery passed in $columns must bypass the cache');
        $this->assertSame(2, (int) $fresh->first()->comment_count);
    }

    #[Test]
    public function it_bypasses_raw_subquery_columns_when_paginating()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        Comment::create(['post_id' => $post->id, 'author' => 'A', 'body' => 'First']);

        $columns = ['*', DB::raw('(select count(*) from comments) as comment_count')];
        $make = fn() => PostWithRelationships::query()->where('id', $post->id);

        $this->assertSame(1, (int) $make()->paginate(10, $columns)->items()[0]->comment_count);

        Comment::create(['post_id' => $post->id, 'author' => 'B', 'body' => 'Second']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = $make()->paginate(10, $columns);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A raw subquery passed to paginate() must bypass the cache');
        $this->assertSame(2, (int) $fresh->items()[0]->comment_count);
    }

    #[Test]
    public function it_bypasses_where_raw_subqueries()
    {
        $post = PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $make = fn() => PostWithRelationships::whereRaw('id in (select post_id from comments where author = ?)', ['A']);

        $this->assertCount(0, $make()->get());

        Comment::create(['post_id' => $post->id, 'author' => 'A', 'body' => 'First']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = $make()->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'A raw where subquery must bypass the cache');
        $this->assertCount(1, $fresh);
    }

    #[Test]
    public function it_keeps_simple_raw_columns_cacheable()
    {
        $method = new \ReflectionMethod(CacheableBuilder::class, 'shouldBypassCache');
        $method->setAccessible(true);

        $columnBuilder = Post::query()->select([DB::raw('title as a')]);
        $columnBuilder->getCacheKey();
        $this->assertFalse($method->invoke($columnBuilder));

        $whereBuilder = Post::query()->whereRaw('title = ?', [DB::raw('x')]);
        $whereBuilder->getCacheKey();
        $this->assertFalse($method->invoke($whereBuilder));
    }

    #[Test]
    public function it_documents_that_eager_load_constraints_run_on_cache_hits()
    {
        self::$eagerConstraintRuns = 0;

        $author = Author::create(['name' => 'Author', 'email' => 'author@example.com']);
        Post::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
            'author_id' => $author->id,
        ]);

        $constraint = static function ($query) {
            self::$eagerConstraintRuns++;
            $query->where('published', true);
        };

        $make = fn() => Author::with(['posts' => $constraint])->where('id', $author->id);

        $first = $make()->get();
        $this->assertCount(1, $first->first()->posts);
        $runsAfterFirst = self::$eagerConstraintRuns;
        $this->assertGreaterThan(0, $runsAfterFirst);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cached = $make()->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries);
        $this->assertCount(1, $cached->first()->posts);
        $this->assertSame($runsAfterFirst + 1, self::$eagerConstraintRuns);
    }

    #[Test]
    public function it_does_not_scan_loaded_relations_without_a_morph_to()
    {
        $post = ProbePost::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);
        $tag = Tag::create(['name' => 'Tag']);
        $post->tags()->attach($tag->id);

        ProbeCacheableBuilder::$morphScans = 0;

        $loaded = ProbePost::with('tags')->where('id', $post->id)->get();

        $this->assertCount(1, $loaded->first()->tags);
        $this->assertSame(0, ProbeCacheableBuilder::$morphScans);
    }

    #[Test]
    public function it_caches_aggregates_even_with_an_after_query_callback()
    {
        PostWithRelationships::create([
            'title' => 'Post',
            'content' => 'Content',
            'published' => true,
        ]);

        $make = fn() => PostWithRelationships::query()->afterQuery(fn($models) => $models);

        $this->assertSame(1, $make()->count());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(1, $make()->count());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'afterQuery callbacks do not apply to aggregates, so they must be cached');
    }

    #[Test]
    public function it_distinguishes_binary_bindings_in_cache_keys()
    {
        $a = Post::query()->whereRaw('title = ?', ["\xFF"])->getCacheKey();
        $b = Post::query()->whereRaw('title = ?', ["\xFE"])->getCacheKey();

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function it_distinguishes_binding_types_in_cache_keys()
    {
        $a = Post::query()->where('views', 1)->getCacheKey();
        $b = Post::query()->where('views', '1')->getCacheKey();

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function it_keys_large_binding_lists_without_collapsing_values()
    {
        $a = Post::query()->whereIn('id', range(1, 1000))->getCacheKey();
        $b = Post::query()->whereIn('id', range(2, 1001))->getCacheKey();

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function it_bypasses_unserializable_binding_values()
    {
        $method = new \ReflectionMethod(CacheableBuilder::class, 'shouldBypassCache');
        $method->setAccessible(true);

        $closure = Post::query()->whereRaw('title = ?', [static fn() => 'x']);
        $closure->getCacheKey();
        $this->assertTrue($method->invoke($closure));

        $resource = fopen('php://memory', 'r');
        $resourceBuilder = Post::query()->whereRaw('title = ?', [$resource]);
        $resourceBuilder->getCacheKey();
        $this->assertTrue($method->invoke($resourceBuilder));
        fclose($resource);
    }

    #[Test]
    public function it_distinguishes_binary_bindings_in_eager_load_constraints()
    {
        $a = PostWithRelationships::with([
            'author' => static fn($query) => $query->whereRaw('name = ?', ["\xFF"]),
        ])->getCacheKey();
        $b = PostWithRelationships::with([
            'author' => static fn($query) => $query->whereRaw('name = ?', ["\xFE"]),
        ])->getCacheKey();

        $this->assertNotSame($a, $b);
    }
}
