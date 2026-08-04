<?php

namespace YMigVal\LaravelModelCache\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Records every log message written through the application logger,
 * so tests can assert what the package actually logs.
 */
class RecorderLogger extends AbstractLogger
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message];
    }
}
