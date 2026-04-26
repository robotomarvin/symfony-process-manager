<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler;

final readonly class PoolSnapshot
{
    public function __construct(
        public string $transport,
        public int $currentWorkers,
        public float $busyWorkers,
        public float $idleWorkers,
        public float $throughputPerSecond,
        public ?int $queueDepth,
        public int $min,
        public int $max,
        public float $secondsSinceLastScaleUp,
        public float $secondsSinceLastScaleDown,
        public int $recentFailureCount,
    ) {}
}
