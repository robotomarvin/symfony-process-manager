<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

final class HttpServer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function start(LoopInterface $loop, string $host, int $port): void
    {
        $http = new ReactHttpServer(function (): Response {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{"status":"ok"}',
            );
        });

        $socket = new SocketServer("{$host}:{$port}", [], $loop);
        $http->listen($socket);

        $this->logger->info('HTTP server listening.', [
            'address' => "{$host}:{$port}",
        ]);
    }
}
