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

        $workerCount = $this->parsePositiveInt($input, 'workers') ?? self::DEFAULT_WORKER_COUNT;
        $workerTimeLimit = $this->parsePositiveInt($input, 'worker-time-limit');
        $workerMessageLimit = $this->parsePositiveInt($input, 'worker-message-limit');
        $workerMemoryLimit = $this->parseMemoryLimit($input);

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

    private function parsePositiveInt(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);

        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException(sprintf(
                'The --%s option must be a positive integer, got: %s',
                $option,
                \is_string($value) ? $value : get_debug_type($value),
            ));
        }

        return (int) $value;
    }

    private function parseMemoryLimit(InputInterface $input): ?string
    {
        $value = $input->getOption('worker-memory-limit');

        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || !preg_match('/^\d+[KMG]?$/i', $value)) {
            throw new \InvalidArgumentException(sprintf(
                'The --worker-memory-limit option must be a valid memory value (e.g. 128M), got: %s',
                \is_string($value) ? $value : get_debug_type($value),
            ));
        }

        return $value;
    }
}
