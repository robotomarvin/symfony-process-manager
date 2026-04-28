<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;

#[CoversClass(MetricsRegistry::class)]
final class MetricsRegistryTest extends TestCase
{
    private MetricsRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
    }

    public function testIncrementCounterCreatesAndIncrements(): void
    {
        $this->registry->incrementCounter('worker_starts', 'Worker starts');

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('worker_starts_total 1', $text);
    }

    public function testMultipleIncrementsAccumulate(): void
    {
        $this->registry->incrementCounter('worker_starts', 'Worker starts');
        $this->registry->incrementCounter('worker_starts', 'Worker starts');
        $this->registry->incrementCounter('worker_starts', 'Worker starts');

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('worker_starts_total 3', $text);
    }

    public function testSetGaugeCreatesAndSetsValue(): void
    {
        $this->registry->setGauge('process_manager_running', 1.0, 'Process manager is running');

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('process_manager_running 1.0', $text);
    }

    public function testSetGaugeOverwritesPreviousValue(): void
    {
        $this->registry->setGauge('temperature', 20.0, 'Temperature');
        $this->registry->setGauge('temperature', 25.5, 'Temperature');

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('temperature 25.5', $text);
        self::assertStringNotContainsString('20.0', $text);
    }

    public function testToPrometheusTextReturnsValidFormat(): void
    {
        $this->registry->incrementCounter('requests', 'Total requests', ['method' => 'GET']);
        $this->registry->setGauge('up', 1.0, 'Is up');

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('# HELP requests_total Total requests', $text);
        self::assertStringContainsString('# TYPE requests_total counter', $text);
        self::assertStringContainsString('requests_total{method="GET"} 1', $text);
        self::assertStringContainsString('# HELP up Is up', $text);
        self::assertStringContainsString('# TYPE up gauge', $text);
        self::assertStringContainsString('up 1.0', $text);
    }

    public function testSameMetricNameReusesExistingInstance(): void
    {
        $this->registry->incrementCounter('requests', 'Total requests', ['method' => 'GET']);
        $this->registry->incrementCounter('requests', 'Total requests', ['method' => 'GET']);

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('requests_total{method="GET"} 2', $text);
        self::assertSame(1, substr_count($text, '# HELP'));
    }

    public function testDifferentLabelSetsTrackedIndependently(): void
    {
        $this->registry->incrementCounter('requests', 'Requests', ['method' => 'GET']);
        $this->registry->incrementCounter('requests', 'Requests', ['method' => 'POST']);
        $this->registry->incrementCounter('requests', 'Requests', ['method' => 'GET']);

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('requests_total{method="GET"} 2', $text);
        self::assertStringContainsString('requests_total{method="POST"} 1', $text);
    }

    public function testEmptyRegistryReturnsEmptyString(): void
    {
        $text = $this->registry->toPrometheusText();

        self::assertSame('', $text);
    }

    public function testObserveHistogramRendersBucketsAndCount(): void
    {
        $this->registry->observeHistogram(
            name: 'duration',
            value: 0.3,
            help: 'Duration',
            buckets: [0.1, 0.5, 1.0],
            labels: ['transport' => 'async'],
        );

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('# TYPE duration histogram', $text);
        self::assertStringContainsString('duration_bucket{transport="async",le="0.5"} 1', $text);
        self::assertStringContainsString('duration_count{transport="async"} 1', $text);
    }

    public function testObserveHistogramReusesExistingHistogram(): void
    {
        $this->registry->observeHistogram('duration', 0.05, 'Duration', [0.1, 1.0]);
        $this->registry->observeHistogram('duration', 0.3, 'Duration', [0.1, 1.0]);

        $text = $this->registry->toPrometheusText();

        self::assertStringContainsString('duration_count 2', $text);
        self::assertSame(1, substr_count($text, '# TYPE duration histogram'));
    }
}
