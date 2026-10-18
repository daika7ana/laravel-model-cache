<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Daika7ana\LaravelModelCache\CacheableBuilder;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Comment;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\TenantAuthor;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\TenantPost;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\TenantState;
use Daika7ana\LaravelModelCache\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class MorphToFingerprintEquivalenceTest extends TestCase
{
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
    public function it_builds_the_same_morph_target_query_as_laravel()
    {
        TenantState::$tenant = 'Alice';

        $author = TenantAuthor::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['title' => 'Post', 'content' => 'Content', 'published' => true]);
        Comment::create([
            'post_id' => $post->id,
            'author' => 'Commenter',
            'body' => 'Hello',
            'commentable_type' => 'tenant_author',
            'commentable_id' => $author->id,
        ]);

        $configure = function ($relation) {
            $relation->morphWith([TenantAuthor::class => 'filteredPosts']);
            $relation->morphWithCount([TenantAuthor::class => 'posts']);
            $relation->constrain([TenantAuthor::class => fn($query) => $query->where('email', 'like', '%@example.com')]);
        };

        DB::flushQueryLog();
        DB::enableQueryLog();
        $comments = Comment::with(['commentable' => $configure])->get();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $actual = null;

        foreach ($log as $entry) {
            if (str_contains($entry['query'], (new TenantAuthor())->getTable())) {
                $actual = $entry;

                break;
            }
        }

        $this->assertNotNull($actual, 'The morph target query must have run');
        $this->assertTrue($comments->first()->commentable->relationLoaded('filteredPosts'));

        $builder = Comment::with(['commentable' => $configure]);
        $relation = $builder->getRelation('commentable');
        $configure($relation);

        $method = new \ReflectionMethod(CacheableBuilder::class, 'morphTargetQuery');
        $method->setAccessible(true);
        [$query, $target] = $method->invoke($builder, $relation, 'tenant_author');

        $ids = Comment::where('commentable_type', 'tenant_author')->pluck('commentable_id')->all();

        $whereInMethod = new \ReflectionMethod($relation, 'whereInMethod');
        $whereInMethod->setAccessible(true);
        $whereIn = $whereInMethod->invoke($relation, $target, $target->getKeyName());

        $query->{$whereIn}($target->qualifyColumn($target->getKeyName()), $ids);
        $scoped = $query->applyScopes();

        $this->assertSame($actual['query'], $scoped->getQuery()->toSql());
        $this->assertSame($actual['bindings'], $scoped->getQuery()->getBindings());
        $this->assertArrayHasKey('filteredPosts', $scoped->getEagerLoads());
    }
}
