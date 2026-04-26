<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use SymfonyProcessManager\ProcessManager\Orchestrator;

#[AsCommand(
    name: 'pm:serve',
    description: 'Run the process manager server.',
)]
final class ServeCommand extends Command
{
    public function __construct(private readonly Orchestrator $orchestrator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input, $output);

        return $this->orchestrator->run();
    }
}
