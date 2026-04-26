<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Transport\TransportConfig;

#[CoversClass(WorkerPool::class)]
final class WorkerPoolTest extends TestCase
{
    public function testInitialPoolHasMinWorkers(): void
    {
        $pool = $this->buildPool(min: 2, max: 10);

        self::assertSame(2, $pool->activeWorkerCount());
        self::assertSame(2, $pool->getTarget());
    }

    public function testWorkerIdsStartFromConfiguredOffset(): void
    {
        $pool = $this->buildPool(min: 2, max: 5, startingId: 7);
        $workers = $pool->workers();

        self::assertSame([7, 8], [$workers[0]->id, $workers[1]->id]);
    }

    public function testSetTargetClampsToMinAndMax(): void
    {
        $pool = $this->buildPool(min: 1, max: 5, scaleUpStep: 100, scaleDownStep: 100);

        $resultUp = $pool->setTarget(20, 100.0);
        self::assertSame(5, $resultUp['target']);

        $resultDown = $pool->setTarget(-10, 1000.0);
        self::assertSame(1, $resultDown['target']);
    }

    public function testStepCapsLimitChange(): void
    {
        $pool = $this->buildPool(min: 1, max: 100, scaleUpStep: 2, scaleDownStep: 1);

        $up = $pool->setTarget(10, 100.0);
        self::assertSame(3, $up['target'], 'May only step up by 2 from initial 1');

        // After scale up, the down cooldown should not block immediate small downscale because cooldown is per-direction.
        $down = $pool->setTarget(0, 100.0 + 1000.0); // far past scale_down_cooldown
        self::assertSame(2, $down['target'], 'May only step down by 1');
    }

    public function testScaleUpCooldownBlocksImmediateRepeat(): void
    {
        $pool = $this->buildPool(
            min: 1,
            max: 100,
            scaleUpStep: 5,
            scaleDownStep: 5,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
        );

        $first = $pool->setTarget(20, 100.0);
        self::assertSame(6, $first['target']);

        $second = $pool->setTarget(20, 110.0);
        self::assertSame('cooldown_up', $second['skip_reason']);
        self::assertSame(6, $second['target']);

        $third = $pool->setTarget(20, 100.0 + 31.0);
        self::assertSame(11, $third['target']);
    }

    public function testScaleDownCooldownIsAsymmetric(): void
    {
        $pool = $this->buildPool(
            min: 1,
            max: 100,
            scaleUpStep: 100,
            scaleDownStep: 100,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
        );
        // Bring up high so we have room to scale down.
        $pool->setTarget(50, 100.0);
        self::assertSame(50, $pool->getTarget());

        // First scale-down: allowed (no prior down).
        $down = $pool->setTarget(20, 200.0);
        self::assertSame(20, $down['target']);

        // 60 seconds later: scale-up's 30s cooldown is past, but down's 300s cooldown is not.
        $stillBlocked = $pool->setTarget(5, 260.0);
        self::assertSame('cooldown_down', $stillBlocked['skip_reason']);
        self::assertSame(20, $stillBlocked['target']);

        // 350s later: down cooldown also expired.
        $allowed = $pool->setTarget(5, 200.0 + 301.0);
        self::assertSame(5, $allowed['target']);
    }

    public function testSetTargetReturnsAtMaxWhenAlreadyAtMax(): void
    {
        $pool = $this->buildPool(min: 1, max: 3, scaleUpStep: 10, scaleDownStep: 10, scaleUpCooldownSec: 0);

        // Bring the pool to its max.
        $pool->setTarget(3, 100.0);
        self::assertSame(3, $pool->getTarget());

        // Any request above max from the boundary must be reported as at_max,
        // not step_cap, even though stepped collapses to previous.
        $skipped = $pool->setTarget(10, 200.0);
        self::assertSame('at_max', $skipped['skip_reason']);
        self::assertFalse($skipped['applied']);
        self::assertSame(3, $skipped['target']);
    }

    public function testSetTargetReturnsAtMinWhenAlreadyAtMin(): void
    {
        $pool = $this->buildPool(min: 2, max: 10, scaleDownStep: 10, scaleDownCooldownSec: 0);

        // Pool starts at min=2. Asking for less must surface at_min, not step_cap.
        $skipped = $pool->setTarget(0, 100.0);
        self::assertSame('at_min', $skipped['skip_reason']);
        self::assertFalse($skipped['applied']);
        self::assertSame(2, $skipped['target']);
    }

    public function testReconcileCreatesAdditionalWorkersOnScaleUp(): void
    {
        $pool = $this->buildPool(min: 1, max: 10, scaleUpStep: 100, scaleDownStep: 1);
        $pool->setTarget(5, 100.0);

        self::assertSame(5, $pool->activeWorkerCount());
    }

    public function testReconcileMovesWorkersToDrainOnScaleDown(): void
    {
        $pool = $this->buildPool(min: 1, max: 10, scaleUpStep: 100, scaleDownStep: 100, scaleDownCooldownSec: 0);
        $pool->setTarget(5, 100.0);

        $drained = $pool->setTarget(2, 200.0);
        self::assertSame(2, $drained['target']);
        self::assertSame(2, $pool->activeWorkerCount());
        self::assertSame(3, count($pool->drainingWorkers()));
    }

    public function testIdleWorkersAreDrainedFirst(): void
    {
        $pool = $this->buildPool(min: 1, max: 10, scaleUpStep: 100, scaleDownStep: 100, scaleDownCooldownSec: 0);
        $pool->setTarget(4, 100.0);

        $workers = $pool->workers();
        // Mark workers 1 and 3 busy; 2 and 4 stay idle.
        $workers[0]->markBusy();
        $workers[2]->markBusy();

        $pool->setTarget(2, 200.0);

        $drained = $pool->drainingWorkers();
        self::assertCount(2, $drained);

        $drainedIds = array_map(static fn($w) => $w->id, $drained);
        self::assertContains($workers[1]->id, $drainedIds);
        self::assertContains($workers[3]->id, $drainedIds);
    }

    public function testHighestIdDrainedAmongIdle(): void
    {
        $pool = $this->buildPool(min: 1, max: 10, scaleUpStep: 100, scaleDownStep: 100, scaleDownCooldownSec: 0);
        $pool->setTarget(4, 100.0);

        $pool->setTarget(2, 200.0);

        $drainedIds = array_map(static fn($w) => $w->id, $pool->drainingWorkers());
        self::assertContains(4, $drainedIds);
        self::assertContains(3, $drainedIds);
    }

    public function testSampleProducesSmoothedThroughputFromMessages(): void
    {
        $pool = $this->buildPool(min: 1, max: 5, smoothingWindowSec: 1);

        $pool->sample(0.0);
        $pool->recordMessageProcessed();
        $pool->recordMessageProcessed();
        $pool->sample(1.0);

        self::assertGreaterThan(0.0, $pool->smoothedThroughput());
    }

    public function testRecentFailureCountAggregatesPerWorker(): void
    {
        $pool = $this->buildPool(min: 2, max: 5);
        $workers = $pool->workers();
        $workers[0]->recordFailure(100.0, 60);
        $workers[1]->recordFailure(101.0, 60);
        $workers[1]->recordFailure(102.0, 60);

        self::assertSame(3, $pool->recentFailureCount());
    }

    public function testDrainAllMovesAllToDraining(): void
    {
        $pool = $this->buildPool(min: 3, max: 5);

        $pool->drainAll();

        self::assertSame(0, $pool->activeWorkerCount());
        self::assertCount(3, $pool->drainingWorkers());
    }

    private function buildPool(
        int $min,
        int $max,
        int $startingId = 1,
        int $scaleUpStep = 2,
        int $scaleDownStep = 1,
        int $smoothingWindowSec = 30,
        int $scaleUpCooldownSec = 30,
        int $scaleDownCooldownSec = 300,
    ): WorkerPool {
        $autoscaler = new AutoscalerConfig(
            min: $min,
            max: $max,
            priority: 0,
            smoothingWindowSec: $smoothingWindowSec,
            scaleUpCooldownSec: $scaleUpCooldownSec,
            scaleDownCooldownSec: $scaleDownCooldownSec,
            scaleUpStep: $scaleUpStep,
            scaleDownStep: $scaleDownStep,
            strategy: StrategyConfig::utilization(),
        );

        $config = TransportConfig::create(transport: 'async', autoscaler: $autoscaler);

        return new WorkerPool($config, $startingId);
    }
}
