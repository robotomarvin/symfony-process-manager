<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

use SymfonyProcessManager\Autoscaler\PoolSnapshot;

final readonly class UtilizationStrategy implements ScalingStrategyInterface
{
    public function __construct(private float $target = 0.7)
    {
        assert($target > 0.0 && $target <= 1.0, 'utilization target must be in (0, 1]');
    }

    public function decide(PoolSnapshot $snapshot): int
    {
        if ($snapshot->busyWorkers <= 0) {
            return 0;
        }

        return (int) ceil($snapshot->busyWorkers / $this->target);
    }
}
