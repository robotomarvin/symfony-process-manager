<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

final class WorkerProcessFactory
{
    private readonly string $projectDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
    }

    public function create(
        ?int $workerTimeLimit,
        ?int $workerMessageLimit,
        ?string $workerMemoryLimit,
    ): Process {
        $command = [
            PHP_BINARY,
            $this->projectDir . '/bin/console',
            'messenger:consume',
        ];

        if ($workerTimeLimit !== null) {
            $command[] = sprintf('--time-limit=%d', $workerTimeLimit);
        }

        if ($workerMessageLimit !== null) {
            $command[] = sprintf('--limit=%d', $workerMessageLimit);
        }

        if ($workerMemoryLimit !== null) {
            $command[] = sprintf('--memory-limit=%s', $workerMemoryLimit);
        }

        $process = new Process($command, $this->projectDir);

        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->enableOutput();

        return $process;
    }
}
