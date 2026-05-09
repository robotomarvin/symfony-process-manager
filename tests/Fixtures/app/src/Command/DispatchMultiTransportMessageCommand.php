<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SymfonyProcessManager\Tests\Fixtures\App\Message\MultiTransportMessageDispatcher;

#[AsCommand(
    name: 'fixture:dispatch-multi',
    description: 'Dispatch a MultiTransportMessage that fans out to both multi_a and multi_b transports.',
)]
final class DispatchMultiTransportMessageCommand extends Command
{
    public function __construct(private readonly MultiTransportMessageDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'payload',
            null,
            InputOption::VALUE_REQUIRED,
            'Payload string for the dispatched message.',
            'multi',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($output);

        $payloadOption = $input->getOption('payload');
        $payload = is_string($payloadOption) ? $payloadOption : 'multi';

        $this->dispatcher->dispatch($payload);

        return Command::SUCCESS;
    }
}
