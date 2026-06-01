# How To Clear Cache

## Clear one model cache

```bash
php artisan mcache:flush "App\Models\User"
```

## Clear all model cache

```bash
php artisan mcache:flush
```

## Clear cache in code

```php
// Clear by model class
User::flushModelCache();

// Clear from model instance
$user = User::find(1);
$user?->flushCache();
```

## When to clear manually

- After large data imports that bypass Eloquent events
- After deployments with schema/query changes
- During debugging of stale reads

## Transaction-aware invalidation

Cache invalidation is automatically deferred when inside `DB::transaction()`. The cache is only flushed after the transaction commits successfully. If the transaction rolls back, the cache remains valid — no manual intervention needed.
