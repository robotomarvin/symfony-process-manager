<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

use SymfonyProcessManager\Autoscaler\PoolSnapshot;

interface ScalingStrategyInterface
{
    public const TAG = 'symfony_process_manager.scaling_strategy';

    public function decide(PoolSnapshot $snapshot): int;
}
