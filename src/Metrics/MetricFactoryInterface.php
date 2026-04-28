<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

interface MetricFactoryInterface
{
    public function createCounter(string $name, string $help): Counter;

    public function createGauge(string $name, string $help): Gauge;

    /**
     * @param list<float|int> $buckets
     */
    public function createHistogram(string $name, string $help, array $buckets): Histogram;
}
