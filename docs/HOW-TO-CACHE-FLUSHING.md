# How to Clear Cache

## Via artisan

```bash
php artisan mcache:flush                    # all cached models
php artisan mcache:flush "App\Models\Post"  # one model
```

## In code

```php
Post::flushModelCache(); // by model class
$post->flushCache();     // from a model instance
```

## When manual flushing is needed

Cache is invalidated automatically on model events and mass operations. Flush manually after changes that bypass Eloquent entirely: bulk imports, raw `DB` writes, or schema/query changes in deployments.

## Transactions

Inside a transaction, flushes are deferred until the commit — a rollback leaves the cache untouched.
