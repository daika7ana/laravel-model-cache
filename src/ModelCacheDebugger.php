<?php

namespace YMigVal\LaravelModelCache;

final class ModelCacheDebugger
{
    /**
     * Log a debug-level message (gated behind debug_mode).
     */
    public function debug(string $message): void
    {
        if (! $this->isDebugModeEnabled()) {
            return;
        }

        $this->log('debug', $message);
    }

    /**
     * Log an info-level message (gated behind debug_mode).
     */
    public function info(string $message): void
    {
        if (! $this->isDebugModeEnabled()) {
            return;
        }

        $this->log('info', $message);
    }

    /**
     * Log a warning-level message (always logged regardless of debug_mode).
     */
    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    /**
     * Log an error-level message (always logged regardless of debug_mode).
     */
    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    /**
     * Write a log message if the logger is available.
     */
    private function log(string $level, string $message): void
    {
        if (function_exists('logger')) {
            logger()->{$level}($message);
        }
    }

    /**
     * Determine whether debug/info logging should run.
     */
    private function isDebugModeEnabled(): bool
    {
        return (bool) config('model-cache.debug_mode', false);
    }
}
