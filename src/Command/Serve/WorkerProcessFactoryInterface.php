<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Symfony\Component\Process\Process;

interface WorkerProcessFactoryInterface
{
    public function create(string $transport, ConsumeArgs $consumeArgs): Process;
}
