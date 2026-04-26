<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

use SymfonyProcessManager\Autoscaler\PoolSnapshot;

final readonly class FixedStrategy implements ScalingStrategyInterface
{
    public function __construct(private int $count)
    {
        assert($count >= 0, 'fixed count must be non-negative');
    }

    public function decide(PoolSnapshot $snapshot): int
    {
        unset($snapshot);

        return $this->count;
    }
}
