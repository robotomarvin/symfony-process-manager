<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\MessageHandler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SymfonyProcessManager\Tests\Fixtures\App\Message\ScalableMessage;

#[AsMessageHandler]
final class ScalableMessageHandler
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(ScalableMessage $message): void
    {
        $this->logger->info('Scalable message handled.', [
            'sleep_seconds' => $message->sleepSeconds,
            'should_fail' => $message->shouldFail,
        ]);

        if ($message->sleepSeconds > 0.0) {
            usleep((int) round($message->sleepSeconds * 1_000_000));
        }

        if ($message->shouldFail) {
            throw new \RuntimeException('Scalable handler failure (intentional).');
        }
    }
}
