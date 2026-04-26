<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

use SymfonyProcessManager\Autoscaler\PoolSnapshot;

interface ScalingStrategyInterface
{
    public function decide(PoolSnapshot $snapshot): int;
}
