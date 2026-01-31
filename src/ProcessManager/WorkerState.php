<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use Symfony\Component\Process\Process;

final class WorkerState
{
    public function __construct(
        public readonly int $id,
        private ?Process $process,
        /** @var array<int, float> */
        private array $failureTimestamps,
        private float $nextStartAt,
        private bool $stopped,
        private bool $stopSignalSent,
    ) {}

    public static function create(int $id): self
    {
        return new self(
            $id,
            null,
            [],
            0.0,
            false,
            false,
        );
    }

    public function markStarted(): void
    {
        $this->stopSignalSent = false;
    }

    public function isStopSignalSent(): bool
    {
        return $this->stopSignalSent;
    }

    public function markStopSignalSent(): void
    {
        $this->stopSignalSent = true;
    }

    public function markStopped(): void
    {
        $this->stopped = true;
    }

    public function setProcess(Process $process): void
    {
        $this->process = $process;
    }

    public function getProcess(): Process
    {
        assert($this->process !== null, 'Cannot get process: no process is assigned to worker ' . $this->id);

        return $this->process;
    }

    public function clearProcess(): void
    {
        $this->process = null;
    }

    public function hasProcess(): bool
    {
        return $this->process !== null;
    }

    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    public function shouldStart(float $now): bool
    {
        return $this->process === null
            && !$this->stopped
            && $this->nextStartAt <= $now;
    }

    public function clearFailures(): void
    {
        $this->failureTimestamps = [];
    }

    public function scheduleImmediateRestart(float $now): void
    {
        $this->nextStartAt = $now;
    }

    public function recordFailure(float $now, int $failureWindowSeconds): void
    {
        $this->failureTimestamps[] = $now;
        $this->failureTimestamps = array_values(array_filter(
            $this->failureTimestamps,
            static fn(float $timestamp): bool => $timestamp >= ($now - $failureWindowSeconds),
        ));
    }

    public function getFailureCount(): int
    {
        return count($this->failureTimestamps);
    }

    public function scheduleRestart(float $now, float $delaySeconds): void
    {
        $this->nextStartAt = $now + $delaySeconds;
    }
}
