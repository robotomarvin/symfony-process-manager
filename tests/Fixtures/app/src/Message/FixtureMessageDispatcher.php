<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Message;

use Symfony\Component\Messenger\MessageBusInterface;

final class FixtureMessageDispatcher
{
    public function __construct(private readonly MessageBusInterface $messageBus) {}

    public function dispatch(string $payload, int $count): void
    {
        for ($index = 1; $index <= $count; $index += 1) {
            $messagePayload = $count > 1 ? sprintf('%s-%d', $payload, $index) : $payload;
            $this->messageBus->dispatch(new FixtureMessage($messagePayload));
        }
    }
}
