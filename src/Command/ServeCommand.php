<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SymfonyProcessManager\Command\Serve\WorkerOutputHandler;
use SymfonyProcessManager\Command\Serve\WorkerProcessFactory;
use SymfonyProcessManager\Command\Serve\WorkerState;

#[AsCommand(
    name: 'pm:serve',
    description: 'Run the process manager server.',
)]
final class ServeCommand extends Command
{
    private const DEFAULT_WORKER_COUNT = 2;
    private const FAILURE_LIMIT = 3;
    private const FAILURE_WINDOW_SECONDS = 60;
    private const BACKOFF_BASE_SECONDS = 1;
    private const BACKOFF_MAX_SECONDS = 30;
    private const POLL_INTERVAL_MICROSECONDS = 200000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactory $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'workers',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of worker processes to spawn.',
            (string) self::DEFAULT_WORKER_COUNT,
        );
        $this->addOption(
            'worker-time-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Time limit in seconds for worker processes.',
            null,
        );
        $this->addOption(
            'worker-message-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Message limit for worker processes.',
            null,
        );
        $this->addOption(
            'worker-memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Memory limit for worker processes (e.g. 128M).',
            null,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($output);

        $workersOption = $input->getOption('workers');
        $workerCount = is_numeric($workersOption)
            ? max(1, (int) $workersOption)
            : self::DEFAULT_WORKER_COUNT;
        $workerTimeLimitOption = $input->getOption('worker-time-limit');
        $workerTimeLimit = is_numeric($workerTimeLimitOption) && (int) $workerTimeLimitOption > 0
            ? (int) $workerTimeLimitOption
            : null;
        $workerMessageLimitOption = $input->getOption('worker-message-limit');
        $workerMessageLimit = is_numeric($workerMessageLimitOption) && (int) $workerMessageLimitOption > 0
            ? (int) $workerMessageLimitOption
            : null;
        $workerMemoryLimitOption = $input->getOption('worker-memory-limit');
        $workerMemoryLimit = is_string($workerMemoryLimitOption) && $workerMemoryLimitOption !== ''
            ? $workerMemoryLimitOption
            : null;
        $shutdownRequested = false;
        $shutdownReason = null;

        $this->logger->info('Process manager server started.', [
            'workers' => $workerCount,
        ]);

        if (extension_loaded('pcntl') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$shutdownRequested, &$shutdownReason): void {
                $shutdownRequested = true;
                $shutdownReason = 'signal';
            });
        }

        $workers = [];

        for ($index = 1; $index <= $workerCount; $index += 1) {
            $workers[] = WorkerState::create($index);
        }

        while (true) {
            $now = microtime(true);
            $shouldSleep = true;

            foreach ($workers as $worker) {
                if (!$shutdownRequested && $worker->shouldStart($now)) {
                    $worker->setProcess($this->processFactory->create(
                        $workerTimeLimit,
                        $workerMessageLimit,
                        $workerMemoryLimit,
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
                    $shouldSleep = false;
                    continue;
                }

                if (!$worker->hasProcess()) {
                    continue;
                }

                if ($worker->isRunning()) {
                    if ($shutdownRequested && !$worker->isStopSignalSent()) {
                        $worker->markStopSignalSent();
                        $worker->getProcess()->signal(SIGTERM);
                        $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
                    }
                    continue;
                }

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
                    continue;
                }

                if ($exitCode === 0) {
                    $worker->clearFailures();
                    $worker->scheduleImmediateRestart($now);
                    $this->logger->info('Worker restarting after expected exit.', ['worker' => $worker->id]);
                    $shouldSleep = false;
                    continue;
                }

                $worker->recordFailure($now, self::FAILURE_WINDOW_SECONDS);
                $failureCount = $worker->getFailureCount();

                if ($failureCount > self::FAILURE_LIMIT) {
                    $this->logger->info('Worker failure limit reached.', ['worker' => $worker->id]);
                    $shutdownRequested = true;
                    $shutdownReason = 'failure_limit';
                    $worker->markStopped();
                    continue;
                }

                $delaySeconds = min(
                    self::BACKOFF_BASE_SECONDS * (2 ** ($failureCount - 1)),
                    self::BACKOFF_MAX_SECONDS,
                );

                $worker->scheduleRestart($now, $delaySeconds);
                $this->logger->info('Worker restarting after unexpected exit.', [
                    'worker' => $worker->id,
                    'delay_seconds' => $delaySeconds,
                    'attempt' => $failureCount,
                ]);
            }

            if ($shutdownRequested) {
                $allStopped = true;

                foreach ($workers as $worker) {
                    if ($worker->hasProcess()) {
                        $allStopped = false;
                    }
                }

                if ($allStopped) {
                    $this->logger->info('Process manager shutting down.', [
                        'reason' => $shutdownReason ?? 'completed',
                    ]);
                    return Command::SUCCESS;
                }
            }

            if ($shouldSleep) {
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
        }
    }

}
