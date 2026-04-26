<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use React\Http\Message\ServerRequest;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;
use SymfonyProcessManager\Tests\Support\FakeLoop;

#[CoversClass(HttpServer::class)]
final class HttpServerTest extends TestCase
{
    public function testHealthEndpointReturnsOkJson(): void
    {
        $server = $this->createServer();

        $response = $server->handleRequest(new ServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testMetricsEndpointReturnsPrometheusText(): void
    {
        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $metrics->incrementCounter('requests', 'Total requests');

        $server = $this->createServer($metrics);

        $response = $server->handleRequest(new ServerRequest('GET', '/metrics'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('requests_total', (string) $response->getBody());
    }

    private function createServer(?MetricsRegistry $metrics = null): HttpServer
    {
        return new HttpServer(
            new FakeLoop(),
            $this->createMock(LoggerInterface::class),
            $metrics ?? new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory()),
        );
    }
}
