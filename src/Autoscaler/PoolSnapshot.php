<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler;

final readonly class PoolSnapshot
{
    /**
     * @param list<string> $transports
     * @param array<string, float> $throughputByTransport
     */
    public function __construct(
        public string $consumer,
        public array $transports,
        public int $currentWorkers,
        public float $busyWorkers,
        public float $idleWorkers,
        public float $throughputPerSecond,
        public array $throughputByTransport,
        public ?int $queueDepth,
        public int $min,
        public int $max,
        public ?float $secondsSinceLastScaleUp,
        public ?float $secondsSinceLastScaleDown,
        public int $recentFailureCount,
    ) {}
}
