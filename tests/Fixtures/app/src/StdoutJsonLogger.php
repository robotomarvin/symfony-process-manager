<?php

namespace SymfonyProcessManager\Tests\Fixtures\App;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

final class StdoutJsonLogger implements LoggerInterface
{
    use LoggerTrait;

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $payload = json_encode([
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            $payload = '{"level":"error","message":"json_encode failed","context":{}}';
        }

        fwrite(STDOUT, $payload . PHP_EOL);
    }
}
