<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Tag;
use Daika7ana\LaravelModelCache\Tests\TestCase;

/**
 * Test suite for CacheableBuilder caching functionality.
 *
 * This test suite validates the CacheableBuilder class, which extends Laravel's query builder
 * to add automatic caching support. Tests are organized into two categories:
 *
 * 1. Caching Methods:
 *    - remember(), getFromCache(), firstFromCache()
 *    - Query result caching via various builder methods
 *
 * 2. Cache Key Generation:
 *    - Validates unique cache keys for different queries
 *    - Ensures query-specific and relationship-specific caching
 *
 * Note: Cache invalidation tests (via model events or builder overrides) are consolidated
 * in the dedicated CacheInvalidationTest class to avoid duplication.
 */
class CacheableBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    // ========== Section 1: Caching Methods ==========

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

        // Query again - should be served from the cache (0 database queries)
        DB::enableQueryLog();
        $posts = Post::where('published', true)->remember(30)->get();
        $this->assertCount(1, $posts);
        $this->assertEquals(0, count(DB::getQueryLog()), 'Second remember() query should be served from cache');
        DB::disableQueryLog();
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

        // Query again - should be served from the cache (0 database queries)
        DB::enableQueryLog();
        $posts = Post::where('published', true)->getFromCache();
        $this->assertCount(1, $posts);
        $this->assertEquals(0, count(DB::getQueryLog()), 'Second getFromCache() query should be served from cache');
        DB::disableQueryLog();
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

        // Query again - should be served from the cache (0 database queries)
        DB::enableQueryLog();
        $post = Post::where('published', true)->firstFromCache();
        $this->assertNotNull($post);
        $this->assertEquals(0, count(DB::getQueryLog()), 'Second firstFromCache() query should be served from cache');
        DB::disableQueryLog();
    }

    // ========== Section 2: Cache Key Generation ==========

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
        $keyPublished = Post::where('published', true)->getCacheKey();
        $keyUnpublished = Post::where('published', false)->getCacheKey();
        $this->assertNotEquals($keyPublished, $keyUnpublished, 'Different queries must produce different cache keys');

        $publishedPosts = Post::where('published', true)->get();
        $unpublishedPosts = Post::where('published', false)->get();

        $this->assertCount(1, $publishedPosts);
        $this->assertCount(1, $unpublishedPosts);
    }

    #[Test]
    public function it_uses_configured_hash_algorithm_for_cache_keys()
    {
        config()->set('model-cache.hash_algorithm', 'sha1');

        $cacheKey = Post::query()->where('published', true)->getCacheKey();

        $this->assertSame(40, strlen($cacheKey));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $cacheKey);
    }

    #[Test]
    public function it_throws_for_invalid_hash_algorithm()
    {
        config()->set('model-cache.hash_algorithm', 'not-a-real-hash');

        // Same exception type as the provider's boot-time validation (ModelCacheServiceProvider)
        $this->expectException(\InvalidArgumentException::class);

        Post::query()->where('published', true)->getCacheKey();
    }

    #[Test]
    public function it_generates_different_cache_keys_for_constrained_eager_loads()
    {
        // Same source line, different captured scalar.
        $make = fn(string $name) => fn($query) => $query->where('name', $name);

        $keyA = Post::with(['tags' => $make('alpha')])->getCacheKey();
        $keyB = Post::with(['tags' => $make('beta')])->getCacheKey();
        $keyC = Post::with('tags')->getCacheKey();

        $this->assertNotSame($keyA, $keyB, 'Different captured constraints must not share a cache key');
        $this->assertNotSame($keyA, $keyC, 'A constrained eager load must not share a key with an unconstrained one');
    }

    #[Test]
    public function it_captures_invalid_utf8_scalars_distinctly_in_eager_loads()
    {
        // json_encode() returns false for invalid UTF-8 and JSON_INVALID_UTF8_SUBSTITUTE
        // maps every bad byte to the same replacement char; the fingerprint must not
        // collapse such captured values into one component.
        $make = fn(string $name) => fn($query) => $query->where('name', $name);

        $keyA = Post::with(['tags' => $make("\x80\x81")])->getCacheKey();
        $keyB = Post::with(['tags' => $make("\x82\x83")])->getCacheKey();

        $this->assertNotSame($keyA, $keyB, 'Invalid-UTF-8 captures must still produce distinct keys');
    }

    #[Test]
    public function it_produces_stable_cache_keys_for_equivalent_eager_loads()
    {
        $make = fn(string $name) => fn($query) => $query->where('name', $name);

        $first = Post::with(['tags' => $make('alpha')])->getCacheKey();
        $second = Post::with(['tags' => $make('alpha')])->getCacheKey();

        $this->assertSame($first, $second, 'Equivalent eager-load constraints must produce a stable cache key');
    }

    #[Test]
    public function it_separates_eager_load_constraints_that_read_changing_this_state()
    {
        $make = fn(object $state) => \Closure::bind(
            fn($query) => $query->where('name', $this->name),
            $state,
            self::class,
        );

        $alpha = new \stdClass();
        $alpha->name = 'alpha';
        $beta = new \stdClass();
        $beta->name = 'beta';

        $keyAlpha = Post::with(['tags' => $make($alpha)])->getCacheKey();
        $keyBeta = Post::with(['tags' => $make($beta)])->getCacheKey();

        $this->assertNotSame($keyAlpha, $keyBeta, 'Constraints reading bound $this state must produce distinct keys');
    }

    #[Test]
    public function it_distinguishes_invalid_utf8_bindings_in_the_cache_key()
    {
        $keyA = Post::where('title', "\x80\x81")->getCacheKey();
        $keyB = Post::where('title', "\x82\x83")->getCacheKey();

        $this->assertNotSame($keyA, $keyB, 'Distinct binary bindings must not share a cache key');
    }

    #[Test]
    public function it_resolves_unfingerprintable_eager_load_constraints_instead_of_bypassing()
    {
        // An object holding a closure cannot be fingerprinted from code identity,
        // so the constraint must be resolved against a fresh relation.
        $holder = new class {
            public string $label = 'alpha';

            private \Closure $opaque;

            public function __construct()
            {
                $this->opaque = static fn() => null;
            }
        };

        $constraint = fn($query) => $query->where('name', $holder->label);

        Post::create(['title' => 'alpha', 'content' => 'Content', 'published' => true]);

        // Prime the cache through the resolver.
        Post::with(['tags' => $constraint])->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Post::with(['tags' => $constraint])->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'Resolved opaque constraints must still be cached');
    }

    #[Test]
    public function it_distinguishes_opaque_constraints_that_only_reorder()
    {
        $make = function (string $dir) {
            $holder = new class ($dir) {
                private \Closure $opaque;

                public function __construct(public string $dir)
                {
                    $this->opaque = static fn() => null;
                }
            };

            return fn($query) => $query->orderBy('name', $holder->dir);
        };

        $keyAsc = Post::with(['tags' => $make('asc')])->getCacheKey();
        $keyDesc = Post::with(['tags' => $make('desc')])->getCacheKey();

        $this->assertNotSame($keyAsc, $keyDesc, 'OrderBy-only opaque constraints must not collide');
    }

    #[Test]
    public function it_distinguishes_opaque_constraints_that_only_change_pivot_columns()
    {
        $make = function (string $column) {
            $holder = new class ($column) {
                private \Closure $opaque;

                public function __construct(public string $column)
                {
                    $this->opaque = static fn() => null;
                }
            };

            return fn($query) => $query->withPivot($holder->column);
        };

        $keyA = Post::with(['tags' => $make('created_at')])->getCacheKey();
        $keyB = Post::with(['tags' => $make('updated_at')])->getCacheKey();

        $this->assertNotSame($keyA, $keyB, 'Pivot-only opaque constraints must not collide');
    }

    #[Test]
    public function it_distinguishes_opaque_constraints_that_add_nested_eager_loads()
    {
        $make = function (string $relation) {
            $holder = new class ($relation) {
                private \Closure $opaque;

                public function __construct(public string $relation)
                {
                    $this->opaque = static fn() => null;
                }
            };

            return fn($query) => $query->with($holder->relation);
        };

        $keyA = Post::with(['tags' => $make('comments')])->getCacheKey();
        $keyB = Post::with(['tags' => $make('posts')])->getCacheKey();

        $this->assertNotSame($keyA, $keyB, 'Nested eager loads inside opaque constraints must not collide');
    }

    #[Test]
    public function it_resolves_state_changes_on_a_reused_opaque_holder()
    {
        // Same holder object, same source line — only its state changes. The key
        // must follow the resolved constraint, not an object identity/id marker.
        $holder = new class {
            public string $label = 'alpha';

            private \Closure $opaque;

            public function __construct()
            {
                $this->opaque = static fn() => null;
            }
        };

        $make = fn($state) => fn($query) => $query->where('name', $state->label);

        $keyAlpha = Post::with(['tags' => $make($holder)])->getCacheKey();

        $holder->label = 'beta';

        $keyBeta = Post::with(['tags' => $make($holder)])->getCacheKey();

        $this->assertNotSame($keyAlpha, $keyBeta, 'Changed opaque holder state must produce a distinct key');
    }

    #[Test]
    public function it_keeps_distinct_data_for_each_resolved_constraint()
    {
        $make = function (string $name) {
            $holder = new class ($name) {
                private \Closure $opaque;

                public function __construct(public string $name)
                {
                    $this->opaque = static fn() => null;
                }
            };

            return fn($query) => $query->where('name', $holder->name);
        };

        $alpha = Tag::create(['name' => 'alpha']);
        $beta = Tag::create(['name' => 'beta']);
        $postAlpha = Post::create(['title' => 'A', 'content' => 'C', 'published' => true]);
        $postBeta = Post::create(['title' => 'B', 'content' => 'C', 'published' => true]);
        $postAlpha->tags()->attach($alpha->id);
        $postBeta->tags()->attach($beta->id);

        // Prime both variants.
        Post::with(['tags' => $make('alpha')])->get();
        Post::with(['tags' => $make('beta')])->get();

        $names = fn(string $name) => Post::with(['tags' => $make($name)])->get()
            ->flatMap->tags->pluck('name')->values()->all();

        $this->assertSame(['alpha'], $names('alpha'));
        $this->assertSame(['beta'], $names('beta'));
    }

    #[Test]
    public function it_resolves_one_of_many_eager_load_constraints_without_bypassing()
    {
        $constraint = fn($query) => $query->where('body', 'like', '%x%');

        PostWithRelationships::create(['title' => 'P', 'content' => 'C', 'published' => true]);

        PostWithRelationships::with(['latestComment' => $constraint])->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        PostWithRelationships::with(['latestComment' => $constraint])->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'one-of-many eager loads must resolve instead of bypassing');
    }
}
