<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;

final class ProcessManagerLoop
{
    /**
     * @param list<TransportConfig> $transportConfigs
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactoryInterface $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
        private readonly array $transportConfigs,
    ) {}

    public function run(LoopInterface $loop): int
    {
        $shutdownState = new ShutdownState();
        $workers = $this->initializeWorkers();
        $totalWorkerCount = count($workers);

        $this->logger->info('Process manager server started.', [
            'workers' => $totalWorkerCount,
            'transports' => array_map(
                static fn(TransportConfig $config): string => $config->transport,
                $this->transportConfigs,
            ),
        ]);

        $loop->addSignal(SIGTERM, static function () use ($shutdownState): void {
            $shutdownState->request(ShutdownReason::SIGNAL);
        });

        $intervalSec = $this->getMinPollIntervalMicroseconds() / 1_000_000;
        $exitCode = Command::SUCCESS;

        $loop->addPeriodicTimer($intervalSec, function (TimerInterface $timer) use ($loop, &$workers, $shutdownState, &$exitCode): void {
            $result = $this->tick($workers, $shutdownState);

            if ($result !== null) {
                $exitCode = $result;
                $loop->cancelTimer($timer);
                $loop->stop();
            }
        });

        $loop->run();

        return $exitCode;
    }

    /**
     * @param list<array{WorkerState, TransportConfig}> $workers
     */
    public function tick(array &$workers, ShutdownState $shutdownState): ?int
    {
        $now = (float) $this->clock->now()->format('U.u');

        foreach ($workers as [$worker, $config]) {
            if (!$shutdownState->isRequested() && $worker->shouldStart($now)) {
                $this->startWorker($worker, $config);
                continue;
            }

            if (!$worker->hasProcess()) {
                continue;
            }

            if ($worker->isRunning()) {
                $this->handleRunningWorker($worker, $shutdownState);
                continue;
            }

            $this->handleWorkerExit($worker, $config, $shutdownState, $now);
        }

        if ($shutdownState->isRequested() && $this->allWorkersStopped($workers)) {
            $this->logger->info('Process manager shutting down.', [
                'reason' => $shutdownState->getReason()?->value ?? 'completed',
            ]);
            return Command::SUCCESS;
        }

        return null;
    }

    /**
     * @return list<array{WorkerState, TransportConfig}>
     */
    public function initializeWorkers(): array
    {
        $workers = [];
        $workerId = 1;

        foreach ($this->transportConfigs as $config) {
            for ($i = 0; $i < $config->processes; $i++) {
                $workers[] = [WorkerState::create($workerId), $config];
                $workerId++;
            }
        }

        return $workers;
    }

    private function startWorker(WorkerState $worker, TransportConfig $config): void
    {
        $worker->setProcess($this->processFactory->create(
            $config->transport,
            $config->consumeArgs,
        ));
        $workerId = $worker->id;
        $worker->getProcess()->start(function (string $type, string $buffer) use ($workerId): void {
            $this->outputHandler->handleOutput($workerId, $type, $buffer);
        });
        $worker->markStarted();
        $this->logger->info('Worker started.', [
            'worker' => $worker->id,
            'transport' => $config->transport,
            'pid' => $worker->getProcess()->getPid(),
        ]);
    }

    private function handleRunningWorker(WorkerState $worker, ShutdownState $shutdownState): void
    {
        if ($shutdownState->isRequested() && !$worker->isStopSignalSent()) {
            $worker->markStopSignalSent();
            $worker->getProcess()->signal(SIGTERM);
            $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
        }
    }

    private function handleWorkerExit(
        WorkerState $worker,
        TransportConfig $config,
        ShutdownState $shutdownState,
        float $now,
    ): void {
        $exitCode = $worker->getProcess()->getExitCode();
        $pid = $worker->getProcess()->getPid();
        $this->outputHandler->flush($worker->id);
        $worker->clearProcess();

        $exitCode = $exitCode ?? 1;
        $this->logger->info('Worker exited.', [
            'worker' => $worker->id,
            'transport' => $config->transport,
            'pid' => $pid,
            'exit_code' => $exitCode,
        ]);

        if ($shutdownState->isRequested()) {
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
        $failureCount = $worker->getFailureCount();

        if ($failureCount > $config->failureLimit) {
            $this->logger->error('Worker failure limit reached.', [
                'worker' => $worker->id,
                'transport' => $config->transport,
            ]);
            $shutdownState->request(ShutdownReason::FAILURE_LIMIT);
            $worker->markStopped();
            return;
        }

        $delaySeconds = min(
            $config->backoffBaseSeconds * (2 ** ($failureCount - 1)),
            $config->backoffMaxSeconds,
        );

        $worker->scheduleRestart($now, $delaySeconds);
        $this->logger->warning('Worker restarting after unexpected exit.', [
            'worker' => $worker->id,
            'delay_seconds' => $delaySeconds,
            'attempt' => $failureCount,
        ]);
    }

    /**
     * @param list<array{WorkerState, TransportConfig}> $workers
     */
    private function allWorkersStopped(array $workers): bool
    {
        foreach ($workers as [$worker]) {
            if ($worker->hasProcess()) {
                return false;
            }
        }

        return true;
    }

    private function getMinPollIntervalMicroseconds(): int
    {
        $minMs = PHP_INT_MAX;

        foreach ($this->transportConfigs as $config) {
            $minMs = min($minMs, $config->pollIntervalMs);
        }

        return $minMs * 1000;
    }
}
