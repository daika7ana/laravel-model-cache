<?php

namespace YMigVal\LaravelModelCache;

final class ModelCacheDebugger
{
    /**
     * Cached debug mode lookup.
     *
     * @var bool|null
     */
    private $debugModeEnabled = null;

    /**
     * Cached logger function availability lookup.
     *
     * @var bool|null
     */
    private $loggerFunctionAvailable = null;

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
        if ($this->debugModeEnabled === null) {
            $this->debugModeEnabled = (bool) config('model-cache.debug_mode', false);
        }

        if ($this->loggerFunctionAvailable === null) {
            $this->loggerFunctionAvailable = function_exists('logger');
        }

        return $this->debugModeEnabled && $this->loggerFunctionAvailable;
    }
}
