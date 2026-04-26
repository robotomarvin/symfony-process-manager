<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Message;

use Symfony\Component\Messenger\MessageBusInterface;

final class ScalableMessageDispatcher
{
    public function __construct(private readonly MessageBusInterface $messageBus) {}

    public function dispatch(int $count, float $sleepSeconds): void
    {
        for ($index = 0; $index < $count; $index += 1) {
            $this->messageBus->dispatch(new ScalableMessage($sleepSeconds));
        }
    }
}
