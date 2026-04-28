<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class MetricsRegistry
{
    /** @var array<string, Counter> */
    private array $counters = [];

    /** @var array<string, Gauge> */
    private array $gauges = [];

    /** @var array<string, Histogram> */
    private array $histograms = [];

    public function __construct(
        private readonly PrometheusRendererInterface $renderer,
        private readonly MetricFactoryInterface $metricFactory,
    ) {}

    /**
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, string $help = '', array $labels = []): void
    {
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = $this->metricFactory->createCounter($name, $help);
        }

        $this->counters[$name]->increment($labels);
    }

    /**
     * @param array<string, string> $labels
     */
    public function setGauge(string $name, float $value, string $help = '', array $labels = []): void
    {
        if (!isset($this->gauges[$name])) {
            $this->gauges[$name] = $this->metricFactory->createGauge($name, $help);
        }

        $this->gauges[$name]->set($value, $labels);
    }

    /**
     * @param array<string, string> $labels
     */
    public function removeGauge(string $name, array $labels): void
    {
        if (!isset($this->gauges[$name])) {
            return;
        }

        $this->gauges[$name]->remove($labels);
    }

    /**
     * @param list<float|int> $buckets used only on first observation for a given name
     * @param array<string, string> $labels
     */
    public function observeHistogram(
        string $name,
        float $value,
        string $help = '',
        array $buckets = [],
        array $labels = [],
    ): void {
        if (!isset($this->histograms[$name])) {
            $this->histograms[$name] = $this->metricFactory->createHistogram($name, $help, $buckets);
        }

        $this->histograms[$name]->observe($value, $labels);
    }

    public function toPrometheusText(): string
    {
        return $this->renderer->render($this->counters, $this->gauges, $this->histograms);
    }
}
