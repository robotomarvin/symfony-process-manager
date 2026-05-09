<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc;

final readonly class WorkerMetadata
{
    public function __construct(
        public int $workerId,
        public string $consumer,
    ) {}
}
