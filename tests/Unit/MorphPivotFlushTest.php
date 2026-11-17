<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\CachingMorphToMany;
use Daika7ana\LaravelModelCache\ModelCacheDebugger;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\PostWithRelationships;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Tag;
use Daika7ana\LaravelModelCache\Tests\TestCase;

/**
 * morphToMany() and morphedByMany() pivot writes must invalidate exactly like
 * belongsToMany(), which Laravel's plain MorphToMany does not do.
 *
 * Only eager loads are asserted: relation queries on a pivot read through the
 * base query builder, so they never served from the model cache in the first
 * place.
 */
class MorphPivotFlushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function it_wraps_morph_to_many_relations_in_the_caching_relation()
    {
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);

        $this->assertInstanceOf(CachingMorphToMany::class, $post->morphTags());
    }

    #[Test]
    public function it_invalidates_the_cache_when_a_morph_pivot_row_is_attached()
    {
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);
        $tag = Tag::create(['name' => 'Tag 1']);

        $read = fn() => PostWithRelationships::with('morphTags')->where('id', $post->id)->get()->first();

        $read();

        DB::enableQueryLog();
        $this->assertCount(0, $read()->morphTags, 'Warm eager loads must be served from the cache');
        $this->assertSame(0, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $post->morphTags()->attach($tag->id);

        DB::enableQueryLog();
        $eagerLoaded = $read();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($log, 'A morph pivot write must invalidate the cached eager load');
        $this->assertSame([$tag->id], $eagerLoaded->morphTags->pluck('id')->all());
    }

    #[Test]
    public function it_flushes_once_for_a_morph_pivot_sync_with_changes()
    {
        $post = PostWithRelationships::create([
            'title' => 'Test Post',
            'content' => 'Test Content',
            'published' => true,
        ]);
        $tag1 = Tag::create(['name' => 'Tag 1']);
        $tag2 = Tag::create(['name' => 'Tag 2']);
        $tag3 = Tag::create(['name' => 'Tag 3']);

        $post->morphTags()->attach([$tag1->id, $tag2->id]);

        $spy = new class {
            public int $syncFlushes = 0;

            public function info($message): void
            {
                if (str_contains($message, 'after sync operation')) {
                    $this->syncFlushes++;
                }
            }

            public function debug($message): void {}

            public function warning($message): void {}

            public function error($message): void {}
        };

        $this->app->instance(ModelCacheDebugger::class, $spy);
        config()->set('model-cache.debug_mode', true);

        $post->morphTags()->sync([$tag1->id, $tag3->id]);

        $this->assertSame(1, $spy->syncFlushes);
    }
}
