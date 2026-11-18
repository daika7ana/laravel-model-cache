<?php

namespace Daika7ana\LaravelModelCache\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Daika7ana\LaravelModelCache\Tests\Fixtures\Models\Post;
use Daika7ana\LaravelModelCache\Tests\TestCase;

class PluckCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Post::create(['title' => 'First', 'content' => 'a', 'published' => true, 'views' => 10]);
        Post::create(['title' => 'Second', 'content' => 'b', 'published' => false, 'views' => 20]);
    }

    #[Test]
    public function it_caches_pluck_results()
    {
        $first = Post::orderBy('views')->pluck('title');
        $this->assertEquals(['First', 'Second'], $first->all());

        DB::enableQueryLog();
        $second = Post::orderBy('views')->pluck('title');
        $this->assertEquals($first->all(), $second->all());
        $this->assertCount(0, DB::getQueryLog(), 'Second pluck() should be served from cache');
        DB::disableQueryLog();
    }

    #[Test]
    public function it_keys_pluck_by_column_and_key()
    {
        $titles = Post::orderBy('views')->pluck('title', 'views');
        $contents = Post::orderBy('views')->pluck('content', 'views');

        $this->assertEquals([10 => 'First', 20 => 'Second'], $titles->all());
        $this->assertEquals([10 => 'a', 20 => 'b'], $contents->all());
        $this->assertNotEquals($titles->all(), $contents->all());
    }

    #[Test]
    public function it_applies_casts_in_cached_pluck_results()
    {
        $published = Post::orderBy('views')->pluck('published');

        $this->assertSame([true, false], $published->all());
    }

    #[Test]
    public function it_invalidates_pluck_cache_on_write()
    {
        $before = Post::orderBy('views')->pluck('title');
        $this->assertEquals(['First', 'Second'], $before->all());

        Post::where('title', 'First')->update(['title' => 'Updated']);

        $after = Post::orderBy('views')->pluck('title');
        $this->assertEquals(['Updated', 'Second'], $after->all());
    }

    #[Test]
    public function it_bypasses_pluck_cache_when_disabled()
    {
        config()->set('model-cache.enabled', false);

        Post::orderBy('views')->pluck('title');

        DB::enableQueryLog();
        Post::orderBy('views')->pluck('title');
        $this->assertCount(1, DB::getQueryLog(), 'Disabled caching should hit the database');
        DB::disableQueryLog();
    }
}
