<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class MetricsRegistry
{
    /** @var array<string, Counter> */
    private array $counters = [];

    /** @var array<string, Gauge> */
    private array $gauges = [];

    private readonly PrometheusTextRenderer $renderer;

    public function __construct()
    {
        $this->renderer = new PrometheusTextRenderer();
    }

    /**
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, string $help = '', array $labels = []): void
    {
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = new Counter($name, $help);
        }

        $this->counters[$name]->increment($labels);
    }

    /**
     * @param array<string, string> $labels
     */
    public function setGauge(string $name, float $value, string $help = '', array $labels = []): void
    {
        if (!isset($this->gauges[$name])) {
            $this->gauges[$name] = new Gauge($name, $help);
        }

        $this->gauges[$name]->set($value, $labels);
    }

    public function toPrometheusText(): string
    {
        return $this->renderer->render($this->counters, $this->gauges);
    }
}
