<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

interface PrometheusRendererInterface
{
    /**
     * @param array<string, Counter> $counters
     * @param array<string, Gauge> $gauges
     * @param array<string, Histogram> $histograms
     */
    public function render(array $counters, array $gauges, array $histograms = []): string;
}
