<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Support;

final class ConsoleProcessResult
{
    /**
     * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $records
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $records,
    ) {}
}
