<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Author;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Comment;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Tag;
use Daika7ana\LaravelModelCache\Tests\TestCase;

class DependencyTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function it_bypasses_cache_for_where_in_subqueries()
    {
        $alice = Author::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = Author::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Post::create([
            'title' => 'Alice Post',
            'content' => 'Body',
            'published' => true,
            'author_id' => $alice->id,
        ]);

        $query = fn() => Author::whereIn('id', DB::table('posts')->select('author_id'))->get();

        $this->assertSame([$alice->id], $query()->pluck('id')->all());

        Post::create([
            'title' => 'Bob Post',
            'content' => 'Body',
            'published' => true,
            'author_id' => $bob->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $ids = $query()->pluck('id')->sort()->values()->all();

        $this->assertSame([$alice->id, $bob->id], $ids);
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'The whereIn subquery was served from cache.');
    }

    #[Test]
    public function it_bypasses_cache_for_having_subqueries()
    {
        Post::create([
            'title' => 'Post',
            'content' => 'Body',
            'published' => true,
        ]);

        $query = fn() => Post::select('published')
            ->groupBy('published')
            ->havingRaw('(select count(*) from comments) >= 1')
            ->get();

        $this->assertCount(0, $query());

        Comment::create([
            'post_id' => Post::first()->id,
            'author' => 'Alice',
            'body' => 'First',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertCount(1, $query());
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'The having subquery was served from cache.');
    }

    #[Test]
    public function it_bypasses_cache_for_join_on_subqueries()
    {
        $tag = Tag::create(['name' => 'Alice']);
        $post = Post::create([
            'title' => 'Post',
            'content' => 'Body',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Alice',
            'body' => 'First',
        ]);

        $query = fn() => Post::join('comments', function ($join) {
            $join->on('comments.post_id', '=', 'posts.id')
                ->where('comments.author', '=', DB::raw('(select name from tags limit 1)'));
        })->select('posts.*')->get();

        $this->assertCount(1, $query());

        $tag->update(['name' => 'Bob']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertCount(0, $query());
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'The join ON subquery was served from cache.');
    }

    #[Test]
    public function it_bypasses_cache_for_nested_where_subqueries()
    {
        Post::create([
            'title' => 'Post',
            'content' => 'Body',
            'published' => true,
        ]);

        $query = fn() => Post::where(function ($where) {
            $where->whereRaw('exists (select 1 from comments where comments.post_id = posts.id)');
        })->get();

        $this->assertCount(0, $query());

        Comment::create([
            'post_id' => Post::first()->id,
            'author' => 'Alice',
            'body' => 'First',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertCount(1, $query());
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'The nested where subquery was served from cache.');
    }
}
