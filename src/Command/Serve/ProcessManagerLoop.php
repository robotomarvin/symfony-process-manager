<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;

final class ProcessManagerLoop
{
    private const FAILURE_LIMIT = 3;
    private const FAILURE_WINDOW_SECONDS = 60;
    private const BACKOFF_BASE_SECONDS = 1;
    private const BACKOFF_MAX_SECONDS = 30;
    private const POLL_INTERVAL_MICROSECONDS = 200000;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactory $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
        private readonly int $workerCount,
        private readonly ?int $workerTimeLimit,
        private readonly ?int $workerMessageLimit,
        private readonly ?string $workerMemoryLimit,
    ) {}

    public function run(): int
    {
        $shutdownRequested = false;
        $shutdownReason = null;

        $this->logger->info('Process manager server started.', [
            'workers' => $this->workerCount,
        ]);

        if (extension_loaded('pcntl') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$shutdownRequested, &$shutdownReason): void {
                $shutdownRequested = true;
                $shutdownReason = 'signal';
            });
        }

        $workers = $this->initializeWorkers();

        while (true) {
            $now = (float) $this->clock->now()->format('U.u');
            $shouldSleep = true;

            foreach ($workers as $worker) {
                if (!$shutdownRequested && $worker->shouldStart($now)) {
                    $this->startWorker($worker);
                    $shouldSleep = false;
                    continue;
                }

                if (!$worker->hasProcess()) {
                    continue;
                }

                if ($worker->isRunning()) {
                    $this->handleRunningWorker($worker, $shutdownRequested);
                    continue;
                }

                $skipSleep = $this->handleWorkerExit($worker, $shutdownRequested, $shutdownReason, $now);
                if ($skipSleep) {
                    $shouldSleep = false;
                }
            }

            if ($shutdownRequested && $this->allWorkersStopped($workers)) {
                $this->logger->info('Process manager shutting down.', [
                    'reason' => $shutdownReason ?? 'completed',
                ]);
                return Command::SUCCESS;
            }

            if ($shouldSleep) {
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
        }
    }

    /**
     * @return list<WorkerState>
     */
    private function initializeWorkers(): array
    {
        $workers = [];

        for ($index = 1; $index <= $this->workerCount; $index += 1) {
            $workers[] = WorkerState::create($index);
        }

        return $workers;
    }

    private function startWorker(WorkerState $worker): void
    {
        $worker->setProcess($this->processFactory->create(
            $this->workerTimeLimit,
            $this->workerMessageLimit,
            $this->workerMemoryLimit,
        ));
        $workerId = $worker->id;
        $worker->getProcess()->start(function (string $type, string $buffer) use ($workerId): void {
            $this->outputHandler->handleOutput($workerId, $type, $buffer);
        });
        $worker->markStarted();
        $this->logger->info('Worker started.', [
            'worker' => $worker->id,
            'pid' => $worker->getProcess()->getPid(),
        ]);
    }

    private function handleRunningWorker(WorkerState $worker, bool $shutdownRequested): void
    {
        if ($shutdownRequested && !$worker->isStopSignalSent()) {
            $worker->markStopSignalSent();
            $worker->getProcess()->signal(SIGTERM);
            $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
        }
    }

    private function handleWorkerExit(
        WorkerState $worker,
        bool &$shutdownRequested,
        ?string &$shutdownReason,
        float $now,
    ): bool {
        $exitCode = $worker->getProcess()->getExitCode();
        $pid = $worker->getProcess()->getPid();
        $this->outputHandler->flush($worker->id);
        $worker->clearProcess();

        $exitCode = $exitCode ?? 1;
        $this->logger->info('Worker exited.', [
            'worker' => $worker->id,
            'pid' => $pid,
            'exit_code' => $exitCode,
        ]);

        if ($shutdownRequested) {
            $worker->markStopped();
            return false;
        }

        if ($exitCode === 0) {
            $worker->clearFailures();
            $worker->scheduleImmediateRestart($now);
            $this->logger->info('Worker restarting after expected exit.', ['worker' => $worker->id]);
            return true;
        }

        $worker->recordFailure($now, self::FAILURE_WINDOW_SECONDS);
        $failureCount = $worker->getFailureCount();

        if ($failureCount > self::FAILURE_LIMIT) {
            $this->logger->error('Worker failure limit reached.', ['worker' => $worker->id]);
            $shutdownRequested = true;
            $shutdownReason = 'failure_limit';
            $worker->markStopped();
            return false;
        }

        $delaySeconds = min(
            self::BACKOFF_BASE_SECONDS * (2 ** ($failureCount - 1)),
            self::BACKOFF_MAX_SECONDS,
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
     * @param list<WorkerState> $workers
     */
    private function allWorkersStopped(array $workers): bool
    {
        foreach ($workers as $worker) {
            if ($worker->hasProcess()) {
                return false;
            }
        }

        return true;
    }
}
