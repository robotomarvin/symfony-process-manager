<?php

namespace SymfonyProcessManager\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'pm:serve',
    description: 'Run the process manager server.'
)]
final class ServeCommand extends Command
{
    private const DEFAULT_WORKER_COUNT = 2;
    private const FAILURE_LIMIT = 3;
    private const FAILURE_WINDOW_SECONDS = 60;
    private const BACKOFF_BASE_SECONDS = 1;
    private const BACKOFF_MAX_SECONDS = 30;
    private const POLL_INTERVAL_MICROSECONDS = 200000;

    private readonly string $projectDir;

    public function __construct(
        private readonly LoggerInterface $logger,
        KernelInterface $kernel
    )
    {
        $this->projectDir = $kernel->getProjectDir();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'workers',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of worker processes to spawn.',
            (string) self::DEFAULT_WORKER_COUNT
        );
        $this->addOption(
            'worker-time-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Time limit in seconds for worker processes.',
            null
        );
        $this->addOption(
            'worker-message-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Message limit for worker processes.',
            null
        );
        $this->addOption(
            'worker-memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Memory limit for worker processes (e.g. 128M).',
            null
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
                if ($worker->process === null) {
                    if ($shutdownRequested || $worker->stopped) {
                        continue;
                    }

                    if ($worker->nextStartAt > $now) {
                        continue;
                    }

                    $worker->process = $this->createWorkerProcess(
                        $workerTimeLimit,
                        $workerMessageLimit,
                        $workerMemoryLimit
                    );
                    $worker->process->start();
                    $worker->stopSignalSent = false;
                    $this->logger->info('Worker started.', [
                        'worker' => $worker->id,
                        'pid' => $worker->process->getPid(),
                    ]);
                    $shouldSleep = false;
                    continue;
                }

                if ($worker->process->isRunning()) {
                    if ($shutdownRequested && !$worker->stopSignalSent) {
                        $worker->stopSignalSent = true;
                        $worker->process->signal(SIGTERM);
                        $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
                    }
                    continue;
                }

                $exitCode = $worker->process->getExitCode();
                $pid = $worker->process->getPid();
                $worker->process = null;

                $exitCode = $exitCode ?? 1;
                $this->logger->info('Worker exited.', [
                    'worker' => $worker->id,
                    'pid' => $pid,
                    'exit_code' => $exitCode,
                ]);

                if ($shutdownRequested) {
                    $worker->stopped = true;
                    continue;
                }

                if ($exitCode === 0) {
                    $worker->failureTimestamps = [];
                    $worker->nextStartAt = $now;
                    $this->logger->info('Worker restarting after expected exit.', ['worker' => $worker->id]);
                    $shouldSleep = false;
                    continue;
                }

                $worker->failureTimestamps[] = $now;
                $worker->failureTimestamps = array_values(array_filter(
                    $worker->failureTimestamps,
                    static fn (float $timestamp): bool => $timestamp >= ($now - self::FAILURE_WINDOW_SECONDS)
                ));

                $failureCount = count($worker->failureTimestamps);

                if ($failureCount > self::FAILURE_LIMIT) {
                    $this->logger->info('Worker failure limit reached.', ['worker' => $worker->id]);
                    $shutdownRequested = true;
                    $shutdownReason = 'failure_limit';
                    $worker->stopped = true;
                    continue;
                }

                $delaySeconds = min(
                    self::BACKOFF_BASE_SECONDS * (2 ** ($failureCount - 1)),
                    self::BACKOFF_MAX_SECONDS
                );

                $worker->nextStartAt = $now + $delaySeconds;
                $this->logger->info('Worker restarting after unexpected exit.', [
                    'worker' => $worker->id,
                    'delay_seconds' => $delaySeconds,
                    'attempt' => $failureCount,
                ]);
            }

            if ($shutdownRequested) {
                $allStopped = true;

                foreach ($workers as $worker) {
                    if ($worker->process !== null) {
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

    private function createWorkerProcess(
        ?int $workerTimeLimit,
        ?int $workerMessageLimit,
        ?string $workerMemoryLimit
    ): Process
    {
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

        return $process;
    }
}

final class WorkerState
{
    /**
     * @param array<int, float> $failureTimestamps
     */
    public function __construct(
        public readonly int $id,
        public ?Process $process,
        public array $failureTimestamps,
        public float $nextStartAt,
        public bool $stopped,
        public bool $stopSignalSent
    ) {
    }

    public static function create(int $id): self
    {
        return new self(
            $id,
            null,
            [],
            0.0,
            false,
            false
        );
    }
}
