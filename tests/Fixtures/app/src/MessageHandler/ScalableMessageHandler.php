<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SymfonyProcessManager\Tests\Fixtures\App\Message\ScalableMessage;

#[AsMessageHandler]
final class ScalableMessageHandler
{
    public function __invoke(ScalableMessage $message): void
    {
        if ($message->sleepSeconds > 0.0) {
            usleep((int) round($message->sleepSeconds * 1_000_000));
        }
    }
}
