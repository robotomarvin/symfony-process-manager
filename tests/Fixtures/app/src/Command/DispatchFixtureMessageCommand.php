<?php

namespace SymfonyProcessManager\Tests\Fixtures\App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SymfonyProcessManager\Tests\Fixtures\App\Message\FixtureMessageDispatcher;

#[AsCommand(
    name: 'fixture:dispatch',
    description: 'Dispatch fixture Messenger messages.',
)]
final class DispatchFixtureMessageCommand extends Command
{
    public function __construct(private readonly FixtureMessageDispatcher $dispatcher)
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
            'payload',
            null,
            InputOption::VALUE_REQUIRED,
            'Payload to dispatch in the fixture message.',
            'message',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($output);

        $countOption = $input->getOption('count');
        $count = is_numeric($countOption) ? max(1, (int) $countOption) : 1;

        $payloadOption = $input->getOption('payload');
        $payload = is_string($payloadOption) ? $payloadOption : 'message';

        $this->dispatcher->dispatch($payload, $count);

        return Command::SUCCESS;
    }
}
