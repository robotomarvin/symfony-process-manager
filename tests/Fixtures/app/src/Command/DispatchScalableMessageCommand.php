<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SymfonyProcessManager\Tests\Fixtures\App\Message\ScalableMessageDispatcher;

#[AsCommand(
    name: 'fixture:dispatch-scalable',
    description: 'Dispatch slow ScalableMessage messages onto the scalable transport.',
)]
final class DispatchScalableMessageCommand extends Command
{
    public function __construct(private readonly ScalableMessageDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'count',
            null,
            InputOption::VALUE_REQUIRED,
            'Number of messages to dispatch.',
            '1',
        );
        $this->addOption(
            'sleep',
            null,
            InputOption::VALUE_REQUIRED,
            'Seconds the handler should sleep per message.',
            '1.0',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($output);

        $countOption = $input->getOption('count');
        $count = is_numeric($countOption) ? max(1, (int) $countOption) : 1;

        $sleepOption = $input->getOption('sleep');
        $sleepSeconds = is_numeric($sleepOption) ? max(0.0, (float) $sleepOption) : 0.0;

        $this->dispatcher->dispatch($count, $sleepSeconds);

        return Command::SUCCESS;
    }
}
