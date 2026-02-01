<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc\Filter;

interface WorkerFilterInterface
{
    public function matches(int $workerId): bool;
}
