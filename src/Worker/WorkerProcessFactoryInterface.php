<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Worker;

use Symfony\Component\Process\Process;
use SymfonyProcessManager\Transport\ConsumeArgs;

interface WorkerProcessFactoryInterface
{
    public function create(string $transport, ConsumeArgs $consumeArgs): Process;
}
