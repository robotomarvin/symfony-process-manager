<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

final class WorkerProcessFactory implements WorkerProcessFactoryInterface
{
    private readonly string $projectDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
    }

    public function create(string $transport, ConsumeArgs $consumeArgs): Process
    {
        $command = [
            PHP_BINARY,
            $this->projectDir . '/bin/console',
            'messenger:consume',
            $transport,
            ...$consumeArgs->toCliArguments(),
        ];

        $process = new Process($command, $this->projectDir);

        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->enableOutput();

        return $process;
    }
}
