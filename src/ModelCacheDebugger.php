<?php

namespace YMigVal\LaravelModelCache;

final class ModelCacheDebugger
{
    /**
     * Log a debug-level message when enabled.
     */
    public function debug(string $message): void
    {
        if (! $this->shouldLogDebugMessages()) {
            return;
        }

        logger()->debug($message);
    }

    /**
     * Log an info-level message when enabled.
     */
    public function info(string $message): void
    {
        if (! $this->shouldLogDebugMessages()) {
            return;
        }

        logger()->info($message);
    }

    /**
     * Log an error-level message when enabled.
     */
    public function error(string $message): void
    {
        if (! $this->shouldLogDebugMessages()) {
            return;
        }

        logger()->error($message);
    }

    /**
     * Determine whether debug logging should run.
     */
    private function shouldLogDebugMessages(): bool
    {
        return (bool) config('model-cache.debug_mode', false) && function_exists('logger');
    }
}
