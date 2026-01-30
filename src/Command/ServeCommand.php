<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SymfonyProcessManager\Command\Serve\ProcessManagerLoop;
use SymfonyProcessManager\Command\Serve\WorkerOutputHandler;
use SymfonyProcessManager\Command\Serve\WorkerProcessFactoryInterface;

#[AsCommand(
    name: 'pm:serve',
    description: 'Run the process manager server.',
)]
final class ServeCommand extends Command
{
    private const DEFAULT_WORKER_COUNT = 2;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactoryInterface $processFactory,
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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $this->processFactory,
            $this->outputHandler,
            $workerCount,
            $workerTimeLimit,
            $workerMessageLimit,
            $workerMemoryLimit,
        );

        return $loop->run();
    }
}
