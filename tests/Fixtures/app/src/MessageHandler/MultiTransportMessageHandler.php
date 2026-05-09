<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\MessageHandler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SymfonyProcessManager\Tests\Fixtures\App\Message\MultiTransportMessage;

#[AsMessageHandler]
final class MultiTransportMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(MultiTransportMessage $message): void
    {
        $this->logger->info('MultiTransport message handled.', [
            'payload' => $message->payload,
        ]);
    }
}
