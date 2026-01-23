<?php

namespace SymfonyProcessManager\Command\Serve;

use Symfony\Component\Process\Process;

final class WorkerState
{
    /**
     * @param array<int, float> $failureTimestamps
     */
    public function __construct(
        public readonly int $id,
        public ?Process $process,
        public array $failureTimestamps,
        public float $nextStartAt,
        public bool $stopped,
        public bool $stopSignalSent
    ) {
    }

    public static function create(int $id): self
    {
        return new self(
            $id,
            null,
            [],
            0.0,
            false,
            false
        );
    }
}
