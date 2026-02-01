<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc\Filter;

final readonly class WorkerIdFilter implements WorkerFilterInterface
{
    public function __construct(
        private int $workerId,
    ) {}

    public function matches(int $workerId): bool
    {
        return $this->workerId === $workerId;
    }
}
