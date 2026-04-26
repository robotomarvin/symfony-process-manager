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
            $this->strategies[$pool->transport()] = $this->strategyRegistry->build($pool->config->autoscaler->strategy);
        }

        $this->loop->addPeriodicTimer((float) $this->intervalSec, function (): void {
            $this->evaluate();
        });
    }

    public function evaluate(): void
    {
        $now = (float) $this->clock->now()->format('U.u');

        $desiredByTransport = [];
        $poolsByTransport = [];

        foreach ($this->pools as $pool) {
            $pool->sample($now);
            $snapshot = $this->buildSnapshot($pool, $now);
            $strategy = $this->strategies[$pool->transport()];
            $desired = $strategy->decide($snapshot);
            $desiredByTransport[$pool->transport()] = $desired;
            $poolsByTransport[$pool->transport()] = $pool;
        }

        if ($this->arbiter !== null) {
            $allocated = $this->arbiter->allocate($desiredByTransport, $poolsByTransport);
        } else {
            $allocated = $desiredByTransport;
        }

        foreach ($poolsByTransport as $transport => $pool) {
            $allocatedTarget = $allocated[$transport];
            $desired = $desiredByTransport[$transport];
            $previousTarget = $pool->getTarget();
            $result = $pool->setTarget($allocatedTarget, $now);

            $this->emitMetrics($pool, $result, $desired, $allocatedTarget);

            if ($result['applied']) {
                $direction = $result['target'] > $previousTarget ? 'up' : 'down';
                $this->logger->info('Autoscaler adjusted target.', [
                    'transport' => $transport,
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
        $secondsSinceLastScaleUp = $pool->lastScaledUpAt() === 0.0 ? PHP_INT_MAX : $now - $pool->lastScaledUpAt();
        $secondsSinceLastScaleDown = $pool->lastScaledDownAt() === 0.0 ? PHP_INT_MAX : $now - $pool->lastScaledDownAt();

        return new PoolSnapshot(
            transport: $pool->transport(),
            currentWorkers: $pool->activeWorkerCount(),
            busyWorkers: $pool->smoothedBusy(),
            idleWorkers: $pool->smoothedIdle(),
            throughputPerSecond: $pool->smoothedThroughput(),
            queueDepth: null,
            min: $auto->min,
            max: $auto->max,
            secondsSinceLastScaleUp: (float) $secondsSinceLastScaleUp,
            secondsSinceLastScaleDown: (float) $secondsSinceLastScaleDown,
            recentFailureCount: $pool->recentFailureCount(),
        );
    }

    /**
     * @param array{previous: int, target: int, raw: int, clamped: int, stepped: int, applied: bool, skip_reason: ?string} $result
     */
    private function emitMetrics(WorkerPool $pool, array $result, int $desired, int $allocated): void
    {
        $transport = $pool->transport();
        $labels = ['transport' => $transport];

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
                ['transport' => $transport, 'reason' => $result['skip_reason']],
            );
        }
    }
}
