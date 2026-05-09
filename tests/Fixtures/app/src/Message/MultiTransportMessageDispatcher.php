<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Message;

use Symfony\Component\Messenger\MessageBusInterface;

final class MultiTransportMessageDispatcher
{
    public function __construct(private readonly MessageBusInterface $messageBus) {}

    public function dispatch(string $payload): void
    {
        $this->messageBus->dispatch(new MultiTransportMessage($payload));
    }
}
