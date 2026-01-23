<?php

namespace SymfonyProcessManager\Tests\Fixtures\App\MessageHandler;

use Psr\Log\LoggerInterface;
use SymfonyProcessManager\Tests\Fixtures\App\Message\FixtureMessage;

final class FixtureMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(FixtureMessage $message): void
    {
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
