<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class MetricFactory implements MetricFactoryInterface
{
    public function createCounter(string $name, string $help): Counter
    {
        return new Counter($name, $help);
    }

    public function createGauge(string $name, string $help): Gauge
    {
        return new Gauge($name, $help);
    }

    public function createHistogram(string $name, string $help, array $buckets): Histogram
    {
        return new Histogram($name, $help, $buckets);
    }
}
