<?php

namespace Daika7ana\LaravelModelCache;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A morphToMany/morphedByMany relation that flushes both models' caches after
 * a pivot change. Without it, polymorphic pivot writes leave every cached
 * relation query stale until the TTL expires.
 */
class CachingMorphToMany extends MorphToMany
{
    use FlushesPivotCache;
}
