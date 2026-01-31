<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use SymfonyProcessManager\Metrics\MetricsRegistry;

final class HttpServer
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MetricsRegistry $metrics,
    ) {}

    public function start(LoopInterface $loop, string $host, int $port): void
    {
        $http = new ReactHttpServer(function (ServerRequestInterface $request): Response {
            if ($request->getUri()->getPath() === '/metrics') {
                return new Response(
                    200,
                    ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
                    $this->metrics->toPrometheusText(),
                );
            }

            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{"status":"ok"}',
            );
        });

        $socket = new SocketServer("{$host}:{$port}", [], $loop);
        $http->listen($socket);

        $this->logger->info('HTTP server listening.', [
            'address' => $socket->getAddress(),
        ]);
    }
}
