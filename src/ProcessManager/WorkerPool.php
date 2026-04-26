<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use SymfonyProcessManager\Autoscaler\Smoother\Ewma;
use SymfonyProcessManager\Transport\TransportConfig;

final class WorkerPool
{
    /** @var list<WorkerState> */
    private array $workers = [];

    /** @var list<WorkerState> */
    private array $draining = [];

    private int $target;

    private float $lastScaledUpAt = 0.0;
    private float $lastScaledDownAt = 0.0;

    private int $nextWorkerId;
    private int $messagesProcessedSinceLastSample = 0;
    private ?float $lastSampleAt = null;

    private readonly Ewma $busyEwma;
    private readonly Ewma $idleEwma;
    private readonly Ewma $throughputEwma;

    public function __construct(
        public readonly TransportConfig $config,
        int $startingWorkerId,
    ) {
        $this->target = $this->config->autoscaler->min;
        $this->nextWorkerId = $startingWorkerId;

        $window = (float) $this->config->autoscaler->smoothingWindowSec;
        $this->busyEwma = new Ewma($window);
        $this->idleEwma = new Ewma($window);
        $this->throughputEwma = new Ewma($window);

        for ($i = 0; $i < $this->config->autoscaler->min; $i++) {
            $this->workers[] = WorkerState::create($this->nextWorkerId++);
        }
    }

    public function transport(): string
    {
        return $this->config->transport;
    }

    /** @return list<WorkerState> */
    public function workers(): array
    {
        return $this->workers;
    }

    /** @return list<WorkerState> */
    public function drainingWorkers(): array
    {
        return $this->draining;
    }

    /** @return list<WorkerState> */
    public function allWorkers(): array
    {
        return [...$this->workers, ...$this->draining];
    }

    public function activeWorkerCount(): int
    {
        return count($this->workers);
    }

    public function busyWorkerCount(): int
    {
        $count = 0;
        foreach ($this->workers as $worker) {
            if ($worker->isBusy()) {
                $count++;
            }
        }

        return $count;
    }

    public function idleWorkerCount(): int
    {
        return $this->activeWorkerCount() - $this->busyWorkerCount();
    }

    public function getTarget(): int
    {
        return $this->target;
    }

    public function recordMessageProcessed(): void
    {
        $this->messagesProcessedSinceLastSample++;
    }

    /**
     * Sample inputs into smoothers based on real elapsed seconds.
     */
    public function sample(float $now): void
    {
        $deltaSeconds = $this->lastSampleAt === null ? 0.0 : max(0.0, $now - $this->lastSampleAt);
        $this->lastSampleAt = $now;

        $busy = (float) $this->busyWorkerCount();
        $idle = (float) $this->idleWorkerCount();
        $throughput = $deltaSeconds > 0.0 ? ($this->messagesProcessedSinceLastSample / $deltaSeconds) : 0.0;
        $this->messagesProcessedSinceLastSample = 0;

        $this->busyEwma->update($deltaSeconds, $busy);
        $this->idleEwma->update($deltaSeconds, $idle);
        $this->throughputEwma->update($deltaSeconds, $throughput);
    }

    public function smoothedBusy(): int
    {
        return (int) round($this->busyEwma->value());
    }

    public function smoothedIdle(): int
    {
        return (int) round($this->idleEwma->value());
    }

    public function smoothedThroughput(): float
    {
        return $this->throughputEwma->value();
    }

    public function lastScaledUpAt(): float
    {
        return $this->lastScaledUpAt;
    }

    public function lastScaledDownAt(): float
    {
        return $this->lastScaledDownAt;
    }

    public function recentFailureCount(): int
    {
        $count = 0;
        foreach ($this->workers as $worker) {
            $count += $worker->getFailureCount();
        }

        return $count;
    }

    /**
     * Apply a desired worker count from the strategy/arbiter, respecting
     * clamp, step caps, and asymmetric cooldowns.
     *
     * Returns details about the decision so the caller can drive metrics.
     *
     * @return array{previous: int, target: int, raw: int, clamped: int, stepped: int, applied: bool, skip_reason: ?string}
     */
    public function setTarget(int $rawDesired, float $now): array
    {
        $previous = $this->target;
        $auto = $this->config->autoscaler;

        $clamped = max($auto->min, min($auto->max, $rawDesired));

        $upBound = $previous + $auto->scaleUpStep;
        $downBound = max(0, $previous - $auto->scaleDownStep);
        $stepped = max($downBound, min($upBound, $clamped));

        $applied = false;
        $skipReason = null;

        if ($stepped > $previous) {
            if (($now - $this->lastScaledUpAt) < $auto->scaleUpCooldownSec && $this->lastScaledUpAt > 0.0) {
                $skipReason = 'cooldown_up';
            } elseif ($previous >= $auto->max) {
                $skipReason = 'at_max';
            } else {
                $this->target = $stepped;
                $this->lastScaledUpAt = $now;
                $applied = true;
            }
        } elseif ($stepped < $previous) {
            if (($now - $this->lastScaledDownAt) < $auto->scaleDownCooldownSec && $this->lastScaledDownAt > 0.0) {
                $skipReason = 'cooldown_down';
            } elseif ($previous <= $auto->min) {
                $skipReason = 'at_min';
            } else {
                $this->target = $stepped;
                $this->lastScaledDownAt = $now;
                $applied = true;
            }
        } else {
            // No change; could be due to step cap if raw direction differed
            if ($rawDesired > $previous && $stepped === $previous) {
                $skipReason = 'step_cap';
            } elseif ($rawDesired < $previous && $stepped === $previous) {
                $skipReason = 'step_cap';
            }
        }

        if ($applied) {
            $this->reconcileWorkers();
        }

        return [
            'previous' => $previous,
            'target' => $this->target,
            'raw' => $rawDesired,
            'clamped' => $clamped,
            'stepped' => $stepped,
            'applied' => $applied,
            'skip_reason' => $skipReason,
        ];
    }

    /**
     * Forcibly mark all active workers as draining (used during full shutdown so the
     * shutdown logic can reuse the same drain mechanism).
     */
    public function drainAll(): void
    {
        foreach ($this->workers as $worker) {
            $worker->markDraining();
            $this->draining[] = $worker;
        }

        $this->workers = [];
    }

    /**
     * Move workers from draining list once their process has been cleared (i.e. exited
     * and clean-up done by the loop).
     */
    public function reapDrained(): void
    {
        $this->draining = array_values(array_filter(
            $this->draining,
            static fn(WorkerState $w): bool => $w->hasProcess(),
        ));
    }

    /**
     * Reconciles the active worker list to match the current target by either:
     *  - adding new WorkerStates (target > active)
     *  - moving idle-first / highest-id workers into draining (target < active)
     */
    private function reconcileWorkers(): void
    {
        $active = count($this->workers);

        if ($active < $this->target) {
            for ($i = $active; $i < $this->target; $i++) {
                $this->workers[] = WorkerState::create($this->nextWorkerId++);
            }
            return;
        }

        if ($active > $this->target) {
            $toDrain = $active - $this->target;
            $picked = $this->pickDrainCandidates($toDrain);

            foreach ($picked as $worker) {
                $worker->markDraining();
                $this->draining[] = $worker;
            }

            $picked_ids = [];
            foreach ($picked as $w) {
                $picked_ids[$w->id] = true;
            }

            $this->workers = array_values(array_filter(
                $this->workers,
                static fn(WorkerState $w) => !isset($picked_ids[$w->id]),
            ));
        }
    }

    /**
     * Pick workers to drain: idle first, then by highest id as a tiebreak.
     *
     * @return list<WorkerState>
     */
    private function pickDrainCandidates(int $count): array
    {
        $sorted = $this->workers;
        usort($sorted, static function (WorkerState $a, WorkerState $b): int {
            $aIdle = $a->isBusy() ? 1 : 0;
            $bIdle = $b->isBusy() ? 1 : 0;

            if ($aIdle !== $bIdle) {
                return $aIdle <=> $bIdle;
            }

            return $b->id <=> $a->id;
        });

        return array_slice($sorted, 0, $count);
    }
}
