<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

use SymfonyProcessManager\Autoscaler\PoolSnapshot;

final readonly class UtilizationStrategy implements ScalingStrategyInterface
{
    private float $scaleUpThreshold;
    private float $scaleDownThreshold;

    public function __construct(
        private float $target = 0.7,
        ?float $scaleUpThreshold = null,
        ?float $scaleDownThreshold = null,
    ) {
        assert($target > 0.0 && $target <= 1.0, 'utilization target must be in (0, 1]');

        // Hysteresis deadband: scale up only above the upper threshold,
        // scale down only below the lower threshold, otherwise hold.
        // Defaults centre the deadband on `target` so existing single-knob
        // configs get sane behaviour without explicit thresholds.
        $this->scaleUpThreshold = $scaleUpThreshold ?? $target;
        $this->scaleDownThreshold = $scaleDownThreshold ?? ($target * 0.5);

        assert(
            $this->scaleDownThreshold <= $this->scaleUpThreshold,
            'scale_down_threshold must not exceed scale_up_threshold',
        );
    }

    public function decide(PoolSnapshot $snapshot): int
    {
        if ($snapshot->busyWorkers <= 0.0) {
            return 0;
        }

        $current = max(1, $snapshot->currentWorkers);
        $util = $snapshot->busyWorkers / $current;

        if ($util > $this->scaleUpThreshold) {
            return (int) ceil($snapshot->busyWorkers / $this->target);
        }

        if ($util < $this->scaleDownThreshold) {
            return (int) max(0, ceil($snapshot->busyWorkers / $this->target));
        }

        return $snapshot->currentWorkers;
    }
}
