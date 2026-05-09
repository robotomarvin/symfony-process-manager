<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\ClockInterface;
use SymfonyProcessManager\Autoscaler\Arbiter\PriorityArbiter;
use SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\ProcessManager\WorkerPool;

final class AutoscalerLoop
{
    /** @var array<string, ScalingStrategyInterface> */
    private array $strategies = [];

    /**
     * @param list<WorkerPool> $pools
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly MetricsRegistry $metrics,
        private readonly StrategyRegistry $strategyRegistry,
        private readonly array $pools,
        private readonly ?PriorityArbiter $arbiter,
        private readonly int $intervalSec = 10,
    ) {}

    public function start(): void
    {
        foreach ($this->pools as $pool) {
            $this->strategies[$pool->label()] = $this->strategyRegistry->build($pool->config->autoscaler->strategy);
        }

        $this->emitInitialMetrics();

        $this->loop->addPeriodicTimer((float) $this->intervalSec, function (): void {
            $this->evaluate();
        });
    }

    /**
     * Emit pool-level gauges for every consumer at boot, before the first
     * periodic evaluation. Without this, gauges are absent from /metrics for
     * up to one autoscaler_interval_sec, which breaks Grafana dashboards that
     * derive the consumer template variable from autoscaler_current_workers
     * and starves Fixed-strategy pools of any visibility until they happen
     * to tick. No strategy or setTarget side-effects: target stays at min.
     */
    private function emitInitialMetrics(): void
    {
        foreach ($this->pools as $pool) {
            $target = $pool->getTarget();
            $result = [
                'previous' => $target,
                'target' => $target,
                'raw' => $target,
                'clamped' => $target,
                'stepped' => $target,
                'applied' => false,
                'skip_reason' => null,
            ];
            $this->emitMetrics($pool, $result, $target, $target);
        }
    }

    public function evaluate(): void
    {
        $now = (float) $this->clock->now()->format('U.u');

        $desiredByConsumer = [];
        $poolsByConsumer = [];

        foreach ($this->pools as $pool) {
            $pool->sample($now);
            $snapshot = $this->buildSnapshot($pool, $now);
            $strategy = $this->strategies[$pool->label()];
            $desired = $strategy->decide($snapshot);
            $desiredByConsumer[$pool->label()] = $desired;
            $poolsByConsumer[$pool->label()] = $pool;
        }

        if ($this->arbiter !== null) {
            $allocated = $this->arbiter->allocate($desiredByConsumer, $poolsByConsumer);
        } else {
            $allocated = $desiredByConsumer;
        }

        foreach ($poolsByConsumer as $consumer => $pool) {
            $allocatedTarget = $allocated[$consumer];
            $desired = $desiredByConsumer[$consumer];
            $previousTarget = $pool->getTarget();
            $result = $pool->setTarget($allocatedTarget, $now);

            $this->emitMetrics($pool, $result, $desired, $allocatedTarget);

            if ($result['applied']) {
                $direction = $result['target'] > $previousTarget ? 'up' : 'down';
                $this->logger->info('Autoscaler adjusted target.', [
                    'consumer' => $consumer,
                    'previous' => $previousTarget,
                    'target' => $result['target'],
                    'desired' => $desired,
                    'allocated' => $allocatedTarget,
                    'direction' => $direction,
                ]);
            }
        }
    }

    private function buildSnapshot(WorkerPool $pool, float $now): PoolSnapshot
    {
        $auto = $pool->config->autoscaler;
        $lastUp = $pool->lastScaledUpAt();
        $lastDown = $pool->lastScaledDownAt();

        return new PoolSnapshot(
            consumer: $pool->label(),
            transports: $pool->transports(),
            currentWorkers: $pool->activeWorkerCount(),
            busyWorkers: $pool->smoothedBusy(),
            idleWorkers: $pool->smoothedIdle(),
            throughputPerSecond: $pool->smoothedThroughput(),
            throughputByTransport: $pool->smoothedThroughputByTransport(),
            queueDepth: null,
            min: $auto->min,
            max: $auto->max,
            secondsSinceLastScaleUp: $lastUp === 0.0 ? null : $now - $lastUp,
            secondsSinceLastScaleDown: $lastDown === 0.0 ? null : $now - $lastDown,
            recentFailureCount: $pool->recentFailureCount(),
        );
    }

    /**
     * @param array{previous: int, target: int, raw: int, clamped: int, stepped: int, applied: bool, skip_reason: ?string} $result
     */
    private function emitMetrics(WorkerPool $pool, array $result, int $desired, int $allocated): void
    {
        $consumer = $pool->label();
        $labels = ['consumer' => $consumer];

        $this->metrics->setGauge('autoscaler_target_workers', (float) $result['target'], 'Last autoscaler target after stability layer', $labels);
        $this->metrics->setGauge('autoscaler_current_workers', (float) $pool->activeWorkerCount(), 'Active worker count per pool', $labels);
        $this->metrics->setGauge('autoscaler_unmet_demand', (float) max(0, $desired - $allocated), 'Desired minus allocated after arbitration', $labels);
        $this->metrics->setGauge('worker_busy_workers', (float) $pool->busyWorkerCount(), 'Currently busy worker count', $labels);

        if ($result['applied']) {
            if ($result['target'] > $result['previous']) {
                $this->metrics->incrementCounter('autoscaler_scale_up', 'Scale-up events', $labels);
            } else {
                $this->metrics->incrementCounter('autoscaler_scale_down', 'Scale-down events', $labels);
            }
        }

        if ($result['skip_reason'] !== null) {
            $this->metrics->incrementCounter(
                'autoscaler_decisions_skipped',
                'Autoscaler decisions skipped by stability layer',
                ['consumer' => $consumer, 'reason' => $result['skip_reason']],
            );
        }
    }
}
