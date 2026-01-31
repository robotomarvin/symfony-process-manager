<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use React\EventLoop\Loop;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;

#[AsCommand(
    name: 'pm:serve',
    description: 'Run the process manager server.',
)]
final class ServeCommand extends Command
{
    public function __construct(
        private readonly HttpServer $httpServer,
        private readonly ProcessManagerLoop $processManagerLoop,
        private readonly string $httpHost = '127.0.0.1',
        private readonly int $httpPort = 9100,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input, $output);

        $eventLoop = Loop::get();

        $this->httpServer->start($eventLoop, $this->httpHost, $this->httpPort);

        return $this->processManagerLoop->run($eventLoop);
    }
}
