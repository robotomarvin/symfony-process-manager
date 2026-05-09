<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use SymfonyProcessManager\Autoscaler\Smoother\Ewma;
use SymfonyProcessManager\Consumer\ConsumerConfig;

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

    /** @var array<string, int> */
    private array $messagesProcessedByTransport = [];

    private ?float $lastSampleAt = null;

    private readonly Ewma $busyEwma;
    private readonly Ewma $idleEwma;

    /** @var array<string, Ewma> */
    private array $throughputEwmaByTransport = [];

    private readonly Ewma $throughputAggregateEwma;

    public function __construct(
        public readonly ConsumerConfig $config,
        int $startingWorkerId,
    ) {
        $this->target = $this->config->autoscaler->min;
        $this->nextWorkerId = $startingWorkerId;

        $window = (float) $this->config->autoscaler->smoothingWindowSec;
        $this->busyEwma = new Ewma($window);
        $this->idleEwma = new Ewma($window);
        $this->throughputAggregateEwma = new Ewma($window);

        foreach ($this->config->transports as $transport) {
            $this->throughputEwmaByTransport[$transport] = new Ewma($window);
            $this->messagesProcessedByTransport[$transport] = 0;
        }

        for ($i = 0; $i < $this->config->autoscaler->min; $i++) {
            $this->workers[] = WorkerState::create($this->nextWorkerId++);
        }
    }

    public function label(): string
    {
        return $this->config->label;
    }

    /** @return list<string> */
    public function transports(): array
    {
        return $this->config->transports;
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

    /**
     * Count of busy workers across the entire pool, including workers
     * currently draining but still finishing their last message. This is
     * the "ground truth" view used for observability — a draining worker
     * mid-message is genuinely busy.
     */
    public function busyWorkerCount(): int
    {
        $count = 0;
        foreach ($this->workers as $worker) {
            if ($worker->isBusy()) {
                $count++;
            }
        }
        foreach ($this->draining as $worker) {
            if ($worker->isBusy()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count of busy workers in the active pool only. Used as input to the
     * autoscaler strategy snapshot, where draining workers must be excluded
     * (they are not future capacity).
     */
    public function activeBusyWorkerCount(): int
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
        return $this->activeWorkerCount() - $this->activeBusyWorkerCount();
    }

    public function getTarget(): int
    {
        return $this->target;
    }

    public function recordMessageProcessed(string $transport): void
    {
        if (!isset($this->messagesProcessedByTransport[$transport])) {
            // Unknown transport (worker reported a transport not in this pool's
            // configured list) — ignored for throughput accounting.
            return;
        }

        $this->messagesProcessedByTransport[$transport]++;
    }

    /**
     * Sample inputs into smoothers based on real elapsed seconds.
     */
    public function sample(float $now): void
    {
        $deltaSeconds = $this->lastSampleAt === null ? 0.0 : max(0.0, $now - $this->lastSampleAt);
        $this->lastSampleAt = $now;

        $busy = (float) $this->activeBusyWorkerCount();
        $idle = (float) $this->idleWorkerCount();

        $aggregateMessages = 0;
        foreach ($this->messagesProcessedByTransport as $transport => $count) {
            $perTransportThroughput = $deltaSeconds > 0.0 ? ($count / $deltaSeconds) : 0.0;
            $this->throughputEwmaByTransport[$transport]->update($deltaSeconds, $perTransportThroughput);
            $aggregateMessages += $count;
            $this->messagesProcessedByTransport[$transport] = 0;
        }

        $aggregateThroughput = $deltaSeconds > 0.0 ? ($aggregateMessages / $deltaSeconds) : 0.0;

        $this->busyEwma->update($deltaSeconds, $busy);
        $this->idleEwma->update($deltaSeconds, $idle);
        $this->throughputAggregateEwma->update($deltaSeconds, $aggregateThroughput);
    }

    public function smoothedBusy(): float
    {
        return $this->busyEwma->value();
    }

    public function smoothedIdle(): float
    {
        return $this->idleEwma->value();
    }

    public function smoothedThroughput(): float
    {
        return $this->throughputAggregateEwma->value();
    }

    /** @return array<string, float> */
    public function smoothedThroughputByTransport(): array
    {
        $out = [];
        foreach ($this->throughputEwmaByTransport as $transport => $ewma) {
            $out[$transport] = $ewma->value();
        }

        return $out;
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

        // Branch on the *raw* direction so boundary saturation (at_max/at_min)
        // is reported even when clamp + step cap collapse $stepped to $previous.
        if ($rawDesired > $previous) {
            if ($previous >= $auto->max) {
                $skipReason = 'at_max';
            } elseif (($now - $this->lastScaledUpAt) < $auto->scaleUpCooldownSec && $this->lastScaledUpAt > 0.0) {
                $skipReason = 'cooldown_up';
            } elseif ($stepped === $previous) {
                $skipReason = 'step_cap';
            } else {
                $this->target = $stepped;
                $this->lastScaledUpAt = $now;
                $applied = true;
            }
        } elseif ($rawDesired < $previous) {
            if ($previous <= $auto->min) {
                $skipReason = 'at_min';
            } elseif (($now - $this->lastScaledDownAt) < $auto->scaleDownCooldownSec && $this->lastScaledDownAt > 0.0) {
                $skipReason = 'cooldown_down';
            } elseif ($stepped === $previous) {
                $skipReason = 'step_cap';
            } else {
                $this->target = $stepped;
                $this->lastScaledDownAt = $now;
                $applied = true;
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

            $pickedIds = [];
            foreach ($picked as $w) {
                $pickedIds[$w->id] = true;
            }

            $this->workers = array_values(array_filter(
                $this->workers,
                static fn(WorkerState $w) => !isset($pickedIds[$w->id]),
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
