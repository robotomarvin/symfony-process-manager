<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Metrics\Counter;
use SymfonyProcessManager\Metrics\Gauge;
use SymfonyProcessManager\Metrics\Histogram;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;

#[CoversClass(PrometheusTextRenderer::class)]
final class PrometheusTextRendererTest extends TestCase
{
    private PrometheusTextRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PrometheusTextRenderer();
    }

    public function testEmptyRegistryReturnsEmptyString(): void
    {
        $result = $this->renderer->render([], []);

        self::assertSame('', $result);
    }

    public function testSingleCounterWithNoLabels(): void
    {
        $counter = new Counter('http_requests', 'Total HTTP requests');
        $counter->increment();
        $counter->increment();

        $result = $this->renderer->render(['http_requests' => $counter], []);

        $expected = <<<'PROM'
        # HELP http_requests_total Total HTTP requests
        # TYPE http_requests_total counter
        http_requests_total 2
        PROM;

        self::assertSame($expected . "\n", $result);
    }

    public function testCounterNameGetsTotalSuffix(): void
    {
        $counter = new Counter('worker_starts', 'Worker starts');
        $counter->increment();

        $result = $this->renderer->render(['worker_starts' => $counter], []);

        self::assertStringContainsString('worker_starts_total 1', $result);
        self::assertStringContainsString('# TYPE worker_starts_total counter', $result);
    }

    public function testSingleGaugeWithLabels(): void
    {
        $gauge = new Gauge('process_memory_bytes', 'Process memory usage');
        $gauge->set(1024.5, ['worker' => 'async']);

        $result = $this->renderer->render([], ['process_memory_bytes' => $gauge]);

        $expected = <<<'PROM'
        # HELP process_memory_bytes Process memory usage
        # TYPE process_memory_bytes gauge
        process_memory_bytes{worker="async"} 1024.5
        PROM;

        self::assertSame($expected . "\n", $result);
    }

    public function testMultipleMetrics(): void
    {
        $counter = new Counter('requests', 'Total requests');
        $counter->increment(['method' => 'GET']);

        $gauge = new Gauge('temperature', 'Current temperature');
        $gauge->set(36.6);

        $result = $this->renderer->render(
            ['requests' => $counter],
            ['temperature' => $gauge],
        );

        self::assertStringContainsString('# HELP requests_total Total requests', $result);
        self::assertStringContainsString('requests_total{method="GET"} 1', $result);
        self::assertStringContainsString('# HELP temperature Current temperature', $result);
        self::assertStringContainsString('# TYPE temperature gauge', $result);
        self::assertStringContainsString('temperature 36.6', $result);
    }

    public function testLabelValueEscapingBackslash(): void
    {
        $counter = new Counter('test', 'test');
        $counter->increment(['path' => 'C:\\Users\\test']);

        $result = $this->renderer->render(['test' => $counter], []);

        self::assertStringContainsString('path="C:\\\\Users\\\\test"', $result);
    }

    public function testLabelValueEscapingQuote(): void
    {
        $counter = new Counter('test', 'test');
        $counter->increment(['msg' => 'say "hello"']);

        $result = $this->renderer->render(['test' => $counter], []);

        self::assertStringContainsString('msg="say \\"hello\\""', $result);
    }

    public function testLabelValueEscapingNewline(): void
    {
        $counter = new Counter('test', 'test');
        $counter->increment(['msg' => "line1\nline2"]);

        $result = $this->renderer->render(['test' => $counter], []);

        self::assertStringContainsString('msg="line1\\nline2"', $result);
    }

    public function testGaugeWithNoLabelsOmitsBraces(): void
    {
        $gauge = new Gauge('up', 'Is up');
        $gauge->set(1.0);

        $result = $this->renderer->render([], ['up' => $gauge]);

        self::assertStringContainsString("up 1.0\n", $result);
        self::assertStringNotContainsString('{', $result);
    }

    public function testMultipleLabelSetsOnSameCounter(): void
    {
        $counter = new Counter('requests', 'Requests');
        $counter->increment(['method' => 'GET']);
        $counter->increment(['method' => 'POST']);
        $counter->increment(['method' => 'GET']);

        $result = $this->renderer->render(['requests' => $counter], []);

        self::assertStringContainsString('requests_total{method="GET"} 2', $result);
        self::assertStringContainsString('requests_total{method="POST"} 1', $result);
    }

    public function testHistogramRendersBucketsSumAndCount(): void
    {
        $histogram = new Histogram('duration', 'Duration', [0.1, 0.5, 1.0]);
        $histogram->observe(0.05);
        $histogram->observe(0.3);
        $histogram->observe(2.0);

        $result = $this->renderer->render([], [], ['duration' => $histogram]);

        self::assertStringContainsString('# HELP duration Duration', $result);
        self::assertStringContainsString('# TYPE duration histogram', $result);
        self::assertStringContainsString('duration_bucket{le="0.1"} 1', $result);
        self::assertStringContainsString('duration_bucket{le="0.5"} 2', $result);
        self::assertStringContainsString('duration_bucket{le="1"} 2', $result);
        self::assertStringContainsString('duration_bucket{le="+Inf"} 3', $result);
        self::assertStringContainsString('duration_count 3', $result);
        self::assertStringContainsString('duration_sum ' . (0.05 + 0.3 + 2.0), $result);
    }

    public function testHistogramRendersWithLabels(): void
    {
        $histogram = new Histogram('duration', 'Duration', [0.1, 1.0]);
        $histogram->observe(0.05, ['transport' => 'async']);

        $result = $this->renderer->render([], [], ['duration' => $histogram]);

        self::assertStringContainsString('duration_bucket{transport="async",le="0.1"} 1', $result);
        self::assertStringContainsString('duration_bucket{transport="async",le="+Inf"} 1', $result);
        self::assertStringContainsString('duration_sum{transport="async"} 0.05', $result);
        self::assertStringContainsString('duration_count{transport="async"} 1', $result);
    }
}
