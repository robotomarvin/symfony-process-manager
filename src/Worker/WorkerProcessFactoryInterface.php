<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Worker;

use Symfony\Component\Process\Process;
use SymfonyProcessManager\Transport\ConsumeArgs;

interface WorkerProcessFactoryInterface
{
    /**
     * @param list<string> $transports
     */
    public function create(array $transports, ConsumeArgs $consumeArgs): Process;
}
