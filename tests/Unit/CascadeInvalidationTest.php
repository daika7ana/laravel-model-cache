<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Comment;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\TestCase;

class CascadeInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    #[Test]
    public function it_flushes_other_models_when_a_delete_cascades()
    {
        $post = Post::create([
            'title' => 'Cascade',
            'content' => 'Body',
            'published' => true,
        ]);

        Comment::create([
            'post_id' => $post->id,
            'author' => 'Alice',
            'body' => 'First',
        ]);

        $this->assertCount(1, Comment::where('post_id', $post->id)->get());

        $post->forceDelete();

        $this->assertSame(0, DB::table('comments')->count());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $comments = Comment::where('post_id', $post->id)->get();

        $this->assertCount(0, $comments);
        $this->assertGreaterThan(0, count(DB::getQueryLog()), 'The cascaded model cache was not flushed.');
    }
}
