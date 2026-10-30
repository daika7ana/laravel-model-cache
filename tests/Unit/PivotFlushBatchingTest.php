<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\ModelCacheDebugger;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Tag;
use Daika7ana\LaravelModelCache\Tests\TestCase;

class PivotFlushBatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function it_flushes_once_for_a_sync_with_changes()
    {
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);
        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);
        $tag3 = Tag::create(['name' => 'Tag 3']);

        $post->tags()->attach([$tag1->id, $tag2->id]);

        $spy = $this->installSpy();

        $post->tags()->sync([$tag1->id, $tag3->id]);

        $this->assertSame(1, $spy->syncFlushes);
        $this->assertSame(2, $spy->staticFlushes);
    }

    #[Test]
    public function it_does_not_flush_when_sync_makes_no_changes()
    {
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);
        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);

        $post->tags()->attach([$tag1->id, $tag2->id]);

        $spy = $this->installSpy();

        $post->tags()->sync([$tag1->id, $tag2->id]);

        $this->assertSame(0, $spy->syncFlushes);
        $this->assertSame(0, $spy->staticFlushes);
    }

    #[Test]
    public function it_flushes_when_sync_fails_after_a_partial_write()
    {
        DB::statement('PRAGMA foreign_keys = ON');

        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);
        $tag = Tag::create(['name' => 'Tag 1']);

        $post->tags()->attach($tag->id);

        // Warm both the eager-load and the relation-query caches.
        PostWithRelationships::with('tags')->find($post->id);
        $post->tags()->get();

        try {
            $post->tags()->sync([$tag->id, 999999]);
            $this->fail('Expected sync() to throw a foreign key violation.');
        } catch (QueryException $e) {
            // expected
        }

        // The valid row was committed before the failing one, so the write is partial.
        $this->assertSame(1, DB::table('post_tag')->count());

        // A stale cache would answer these reads without any SQL.
        DB::enableQueryLog();

        $eagerLoaded = PostWithRelationships::with('tags')->find($post->id);
        $relation = $post->tags()->get();
        $log = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertNotEmpty($log);
        $this->assertSame([$tag->id], $eagerLoaded->tags->pluck('id')->all());
        $this->assertSame([$tag->id], $relation->pluck('id')->all());
    }

    private function installSpy(): object
    {
        $spy = new class {
            public int $syncFlushes = 0;

            public int $staticFlushes = 0;

            public function info($message): void
            {
                if (str_contains($message, 'after sync operation')) {
                    $this->syncFlushes++;
                }

                if (str_contains($message, 'statically')) {
                    $this->staticFlushes++;
                }
            }

            public function debug($message): void {}

            public function warning($message): void {}

            public function error($message): void {}
        };

        $this->app->instance(ModelCacheDebugger::class, $spy);
        config()->set('model-cache.debug_mode', true);

        return $spy;
    }
}
