<?php

namespace SymfonyProcessManager\Tests\Fixtures\App\MessageHandler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SymfonyProcessManager\Tests\Fixtures\App\Message\FixtureMessage;

#[AsMessageHandler]
final class FixtureMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(FixtureMessage $message): void
    {
        if ($message->payload === 'stdout:plain') {
            fwrite(STDOUT, 'fixture plain output' . PHP_EOL);
            fflush(STDOUT);
            return;
        }

        if ($message->payload === 'exit:0') {
            $this->logger->info('Fixture message requested clean exit.');
            exit(0);
        }

        if ($message->payload === 'exit:1') {
            $this->logger->info('Fixture message requested error exit.');
            exit(1);
        }

        $this->logger->info('Fixture message handled.', [
            'payload' => $message->payload,
        ]);
    }
}
