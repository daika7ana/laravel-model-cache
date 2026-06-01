<?php

namespace YMigVal\LaravelModelCache;

trait HasCacheableModel
{
    use HasCachedQueries;
    use HasCachedRelationships;
}
