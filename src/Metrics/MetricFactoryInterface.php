<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

interface MetricFactoryInterface
{
    public function createCounter(string $name, string $help): Counter;

    public function createGauge(string $name, string $help): Gauge;
}
