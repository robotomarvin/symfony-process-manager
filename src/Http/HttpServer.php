<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Http;

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
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly MetricsRegistry $metrics,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 9100,
    ) {}

    public function start(): void
    {
        $http = new ReactHttpServer(fn(ServerRequestInterface $request): Response => $this->handleRequest($request));

        $socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);
        $http->listen($socket);

        $this->logger->info('HTTP server listening.', [
            'address' => $socket->getAddress(),
        ]);
    }

    public function handleRequest(ServerRequestInterface $request): Response
    {
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
    }
}
