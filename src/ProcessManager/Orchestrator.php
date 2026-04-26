<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use React\EventLoop\LoopInterface;
use SymfonyProcessManager\Autoscaler\AutoscalerLoop;
use SymfonyProcessManager\Http\HttpServer;

final class Orchestrator
{
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ShutdownState $shutdown,
        private readonly ProcessManagerLoop $processManagerLoop,
        private readonly AutoscalerLoop $autoscalerLoop,
        private readonly HttpServer $httpServer,
    ) {}

    public function run(): int
    {
        $this->shutdown->install();
        $this->processManagerLoop->start();
        $this->autoscalerLoop->start();
        $this->httpServer->start();

        $this->loop->run();

        return $this->shutdown->getExitCode();
    }
}
