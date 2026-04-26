<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler;

use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;

final readonly class AutoscalerConfig
{
    public function __construct(
        public int $min,
        public int $max,
        public int $priority,
        public int $smoothingWindowSec,
        public int $scaleUpCooldownSec,
        public int $scaleDownCooldownSec,
        public int $scaleUpStep,
        public int $scaleDownStep,
        public StrategyConfig $strategy,
    ) {
        assert($min >= 1, 'min must be >= 1');
        assert($max >= $min, 'max must be >= min');
        assert($smoothingWindowSec > 0, 'smoothing window must be positive');
        assert($scaleUpCooldownSec >= 0, 'scale up cooldown must be non-negative');
        assert($scaleDownCooldownSec >= 0, 'scale down cooldown must be non-negative');
        assert($scaleUpStep >= 1, 'scale up step must be >= 1');
        assert($scaleDownStep >= 1, 'scale down step must be >= 1');
    }

    public static function legacyFixed(int $processes): self
    {
        return new self(
            min: $processes,
            max: $processes,
            priority: PHP_INT_MAX,
            smoothingWindowSec: 30,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
            scaleUpStep: 1,
            scaleDownStep: 1,
            strategy: StrategyConfig::fixed($processes),
        );
    }
}
