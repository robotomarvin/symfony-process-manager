<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\InputStream;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\IpcMessage;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;
use SymfonyProcessManager\Ipc\Message\WorkerStartedHandlingMessage;
use SymfonyProcessManager\Ipc\WorkerContextInterface;
use SymfonyProcessManager\Ipc\WorkerMetadata;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\Transport\TransportConfig;
use SymfonyProcessManager\Worker\WorkerProcessFactoryInterface;

final class ProcessManagerLoop
{
    private int $ticksSinceLastPing = 0;

    /**
     * @param list<WorkerPool> $pools
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ShutdownState $shutdownState,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactoryInterface $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
        private readonly array $pools,
        private readonly MetricsRegistry $metrics,
        private readonly IpcFanout $ipcFanout,
        private readonly WorkerContextInterface $workerContext,
        private readonly ?int $shutdownTimeoutSeconds = 30,
        private readonly int $pingIntervalTicks = 50,
    ) {}

    public function start(): void
    {
        $totalWorkers = 0;
        $transports = [];
        foreach ($this->pools as $pool) {
            $totalWorkers += $pool->activeWorkerCount();
            $transports[] = $pool->transport();
        }

        $this->logger->info('Process manager server started.', [
            'workers' => $totalWorkers,
            'transports' => $transports,
        ]);

        $intervalSec = $this->getMinPollIntervalMicroseconds() / 1_000_000;

        $this->loop->addPeriodicTimer($intervalSec, function () use ($intervalSec): void {
            unset($intervalSec);
            $result = $this->tick();

            if ($result !== null) {
                $this->shutdownState->setExitCode($result);
                $this->loop->stop();
            }
        });
    }

    public function tick(): ?int
    {
        $this->metrics->setGauge('process_manager_running', $this->shutdownState->isRequested() ? 0.0 : 1.0, 'Whether the process manager is running');

        $now = (float) $this->clock->now()->format('U.u');

        if ($this->shutdownState->isRequested()) {
            $this->ensureAllPoolsDraining();
        }

        foreach ($this->pools as $pool) {
            $this->tickPool($pool, $now);
            $pool->reapDrained();
        }

        if (!$this->shutdownState->isRequested()) {
            $this->maybeSendPing();
        }

        if ($this->shutdownState->isRequested() && !$this->shutdownState->isSigkillSent()) {
            $this->escalateToSigkill($now);
        }

        if ($this->shutdownState->isRequested() && $this->allWorkersStopped()) {
            $this->logger->info('Process manager shutting down.', [
                'reason' => $this->shutdownState->getReason()?->value ?? 'completed',
            ]);
            return Command::SUCCESS;
        }

        return null;
    }

    private function tickPool(WorkerPool $pool, float $now): void
    {
        $config = $pool->config;

        foreach ($pool->workers() as $worker) {
            $this->tickWorker($worker, $config, $now, draining: false);
        }

        foreach ($pool->drainingWorkers() as $worker) {
            $this->tickWorker($worker, $config, $now, draining: true);
        }
    }

    private function tickWorker(WorkerState $worker, TransportConfig $config, float $now, bool $draining): void
    {
        if (!$draining && !$this->shutdownState->isRequested() && $worker->shouldStart($now)) {
            $this->startWorker($worker, $config);
            return;
        }

        if (!$worker->hasProcess()) {
            return;
        }

        if ($worker->isRunning()) {
            $this->dispatchIpcMessages($worker, $config, $now);

            if ($draining || $this->shutdownState->isRequested()) {
                $this->sendStopSignal($worker);
            }

            return;
        }

        $this->handleWorkerExit($worker, $config, $now, $draining);
    }

    private function startWorker(WorkerState $worker, TransportConfig $config): void
    {
        $process = $this->processFactory->create(
            $config->transport,
            $config->consumeArgs,
        );

        $inputStream = new InputStream();
        $process->setInput($inputStream);

        $worker->setProcess($process);
        $worker->setInputStream($inputStream);
        $this->ipcFanout->register($worker->id, $inputStream);

        $workerId = $worker->id;
        $process->start(function (string $type, string $buffer) use ($workerId): void {
            $this->outputHandler->handleOutput($workerId, $type, $buffer);
        });
        $worker->markStarted();
        $this->metrics->incrementCounter('worker_starts', 'Total number of worker starts', ['transport' => $config->transport]);
        $this->logger->info('Worker started.', [
            'worker' => $worker->id,
            'transport' => $config->transport,
            'pid' => $process->getPid(),
        ]);
    }

    private function sendStopSignal(WorkerState $worker): void
    {
        if ($worker->isStopSignalSent()) {
            return;
        }

        $worker->markStopSignalSent();
        $worker->getProcess()->signal(SIGTERM);
        $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
    }

    private function handleWorkerExit(
        WorkerState $worker,
        TransportConfig $config,
        float $now,
        bool $draining,
    ): void {
        $exitCode = $worker->getProcess()->getExitCode();
        $pid = $worker->getProcess()->getPid();
        $this->outputHandler->flush($worker->id);
        $this->ipcFanout->unregister($worker->id);
        $worker->getInputStream()?->close();
        $worker->clearInputStream();
        $worker->clearProcess();

        $this->metrics->removeGauge('worker_last_pong_timestamp', ['worker' => (string) $worker->id]);
        $this->metrics->removeGauge('worker_busy', ['worker' => (string) $worker->id, 'transport' => $config->transport]);

        $exitCode = $exitCode ?? 1;
        $this->logger->info('Worker exited.', [
            'worker' => $worker->id,
            'transport' => $config->transport,
            'pid' => $pid,
            'exit_code' => $exitCode,
        ]);
        $this->metrics->incrementCounter('worker_exits', 'Total number of worker exits', ['exit_code' => (string) $exitCode]);

        if ($draining || $this->shutdownState->isRequested()) {
            $worker->markStopped();
            return;
        }

        if ($exitCode === 0) {
            $worker->clearFailures();
            $worker->scheduleImmediateRestart($now);
            $this->logger->info('Worker restarting after expected exit.', ['worker' => $worker->id]);
            return;
        }

        $worker->recordFailure($now, $config->failureWindowSeconds);
        $this->metrics->incrementCounter('worker_failures', 'Total number of worker failures', ['transport' => $config->transport]);
        $failureCount = $worker->getFailureCount();

        if ($failureCount > $config->failureLimit) {
            $this->logger->error('Worker failure limit reached.', [
                'worker' => $worker->id,
                'transport' => $config->transport,
            ]);
            $this->shutdownState->request(ShutdownReason::FAILURE_LIMIT, $now);
            $worker->markStopped();
            return;
        }

        $delaySeconds = min(
            $config->backoffBaseSeconds * (2 ** ($failureCount - 1)),
            $config->backoffMaxSeconds,
        );

        $worker->scheduleRestart($now, $delaySeconds);
        $this->metrics->incrementCounter('worker_backoffs', 'Total number of worker backoffs', ['transport' => $config->transport]);
        $this->logger->warning('Worker restarting after unexpected exit.', [
            'worker' => $worker->id,
            'delay_seconds' => $delaySeconds,
            'attempt' => $failureCount,
        ]);
    }

    private function dispatchIpcMessages(WorkerState $worker, TransportConfig $config, float $now): void
    {
        $messages = $this->outputHandler->getAndClearIpcMessages($worker->id);

        foreach ($messages as $message) {
            $this->workerContext->setCurrent(new WorkerMetadata($worker->id, $config->transport));

            try {
                $this->handleIpcMessage($message, $worker, $config, $now);
            } finally {
                $this->workerContext->clear();
            }
        }
    }

    private function handleIpcMessage(IpcMessage $message, WorkerState $worker, TransportConfig $config, float $now): void
    {
        if ($message instanceof PongMessage) {
            $worker->setLastPongAt($now);
            $this->metrics->setGauge(
                'worker_last_pong_timestamp',
                $now,
                'Timestamp of last pong received from worker',
                ['worker' => (string) $worker->id],
            );
            $this->logger->debug('Pong received.', ['worker' => $worker->id]);

            return;
        }

        if ($message instanceof WorkerStartedHandlingMessage) {
            $worker->markBusy();
            $this->metrics->setGauge(
                'worker_busy',
                1.0,
                'Whether worker is currently busy',
                ['worker' => (string) $worker->id, 'transport' => $config->transport],
            );

            return;
        }

        if ($message instanceof ProcessedCommandMessage) {
            $worker->markIdle();
            $this->metrics->setGauge(
                'worker_busy',
                0.0,
                'Whether worker is currently busy',
                ['worker' => (string) $worker->id, 'transport' => $config->transport],
            );

            $pool = $this->findPool($config->transport);
            $pool?->recordMessageProcessed();

            if ($message->status === 'handled') {
                $this->metrics->incrementCounter('messages_processed', 'Total messages processed', ['transport' => $config->transport]);
            }
        }
    }

    private function findPool(string $transport): ?WorkerPool
    {
        foreach ($this->pools as $pool) {
            if ($pool->transport() === $transport) {
                return $pool;
            }
        }

        return null;
    }

    private function maybeSendPing(): void
    {
        $this->ticksSinceLastPing++;

        if ($this->ticksSinceLastPing >= $this->pingIntervalTicks) {
            $this->ticksSinceLastPing = 0;
            $this->ipcFanout->send(new PingMessage());
        }
    }

    private function ensureAllPoolsDraining(): void
    {
        foreach ($this->pools as $pool) {
            if ($pool->activeWorkerCount() > 0) {
                $pool->drainAll();
            }
        }
    }

    private function escalateToSigkill(float $now): void
    {
        if ($this->shutdownTimeoutSeconds === null) {
            return;
        }

        $requestedAt = $this->shutdownState->getRequestedAt();
        if ($requestedAt === null) {
            return;
        }

        if ($now - $requestedAt < $this->shutdownTimeoutSeconds) {
            return;
        }

        $this->shutdownState->markSigkillSent();

        foreach ($this->pools as $pool) {
            foreach ($pool->allWorkers() as $worker) {
                if (!$worker->isRunning()) {
                    continue;
                }

                $worker->getProcess()->signal(SIGKILL);
                $this->metrics->incrementCounter('worker_sigkills', 'Total number of SIGKILLs sent to workers');
                $this->logger->warning('Sent SIGKILL to worker after shutdown timeout.', [
                    'worker' => $worker->id,
                    'timeout_seconds' => $this->shutdownTimeoutSeconds,
                ]);
            }
        }
    }

    private function allWorkersStopped(): bool
    {
        foreach ($this->pools as $pool) {
            foreach ($pool->allWorkers() as $worker) {
                if ($worker->hasProcess()) {
                    return false;
                }
            }
        }

        return true;
    }

    private function getMinPollIntervalMicroseconds(): int
    {
        $minMs = PHP_INT_MAX;

        foreach ($this->pools as $pool) {
            $minMs = min($minMs, $pool->config->pollIntervalMs);
        }

        if ($minMs === PHP_INT_MAX) {
            $minMs = 200;
        }

        return $minMs * 1000;
    }
}
