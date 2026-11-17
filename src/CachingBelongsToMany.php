<?php

namespace Daika7ana\LaravelModelCache;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A belongsToMany relation that flushes both models' caches after a pivot
 * change, batching sync()'s per-row writes into a single flush — even when a
 * partial write throws.
 */
class CachingBelongsToMany extends BelongsToMany
{
    use FlushesPivotCache;
}
