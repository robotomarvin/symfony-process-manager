<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Psr\Log\LoggerInterface;
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

    public function run(): int
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

        if (extension_loaded('pcntl') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use ($shutdownState): void {
                $shutdownState->request(ShutdownReason::SIGNAL);
            });
        }

        $minPollIntervalUs = $this->getMinPollIntervalMicroseconds();

        while (true) {
            $now = (float) $this->clock->now()->format('U.u');
            $shouldSleep = true;

            foreach ($workers as [$worker, $config]) {
                if (!$shutdownState->isRequested() && $worker->shouldStart($now)) {
                    $this->startWorker($worker, $config);
                    $shouldSleep = false;
                    continue;
                }

                if (!$worker->hasProcess()) {
                    continue;
                }

                if ($worker->isRunning()) {
                    $this->handleRunningWorker($worker, $shutdownState);
                    continue;
                }

                $skipSleep = $this->handleWorkerExit($worker, $config, $shutdownState, $now);
                if ($skipSleep) {
                    $shouldSleep = false;
                }
            }

            if ($shutdownState->isRequested() && $this->allWorkersStopped($workers)) {
                $this->logger->info('Process manager shutting down.', [
                    'reason' => $shutdownState->getReason()?->value ?? 'completed',
                ]);
                return Command::SUCCESS;
            }

            if ($shouldSleep) {
                usleep($minPollIntervalUs);
            }
        }
    }

    /**
     * @return list<array{WorkerState, TransportConfig}>
     */
    private function initializeWorkers(): array
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
    ): bool {
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
            return false;
        }

        if ($exitCode === 0) {
            $worker->clearFailures();
            $worker->scheduleImmediateRestart($now);
            $this->logger->info('Worker restarting after expected exit.', ['worker' => $worker->id]);
            return true;
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
            return false;
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

        return false;
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
