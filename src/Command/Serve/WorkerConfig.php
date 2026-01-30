<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

final readonly class WorkerConfig
{
    public function __construct(
        public int $id,
    ) {}
}
