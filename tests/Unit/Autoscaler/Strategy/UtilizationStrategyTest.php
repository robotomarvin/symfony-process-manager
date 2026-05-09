<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\PoolSnapshot;
use SymfonyProcessManager\Autoscaler\Strategy\UtilizationStrategy;

#[CoversClass(UtilizationStrategy::class)]
final class UtilizationStrategyTest extends TestCase
{
    public function testDecideAtTargetUtilization(): void
    {
        $strategy = new UtilizationStrategy(target: 0.7);

        // 7 busy / 0.7 = 10
        self::assertSame(10, $strategy->decide($this->snapshot(busy: 7)));
    }

    public function testDecideRoundsUp(): void
    {
        $strategy = new UtilizationStrategy(target: 0.7);

        // 5 / 0.7 = 7.14 → 8
        self::assertSame(8, $strategy->decide($this->snapshot(busy: 5)));
    }

    public function testZeroBusyReturnsZero(): void
    {
        $strategy = new UtilizationStrategy(target: 0.7);

        self::assertSame(0, $strategy->decide($this->snapshot(busy: 0)));
    }

    public function testHighTargetMeansFewerWorkers(): void
    {
        $loose = new UtilizationStrategy(target: 0.5);
        $tight = new UtilizationStrategy(target: 1.0);

        self::assertSame(20, $loose->decide($this->snapshot(busy: 10)));
        self::assertSame(10, $tight->decide($this->snapshot(busy: 10)));
    }

    public function testFractionalBusyDoesNotOvershoot(): void
    {
        // Bursty traffic: a single worker is busy ~half the time, so the
        // EWMA settles around 0.5. A correct strategy should NOT see this
        // as "1 fully busy worker" and demand 2 — that would cause flapping.
        $strategy = new UtilizationStrategy(target: 0.5);

        // 0.5 / 0.5 = 1.0 → ceil = 1
        self::assertSame(1, $strategy->decide($this->snapshotAt(current: 5, busy: 0.5)));
        // 0.4 / 0.5 = 0.8 → ceil = 1
        self::assertSame(1, $strategy->decide($this->snapshotAt(current: 5, busy: 0.4)));
        // 0.6 / 0.5 = 1.2 → ceil = 2 (legit: workers genuinely overloaded)
        self::assertSame(2, $strategy->decide($this->snapshotAt(current: 1, busy: 0.6)));
    }

    public function testDeadbandHoldsCurrentBetweenThresholds(): void
    {
        $strategy = new UtilizationStrategy(
            target: 0.7,
            scaleUpThreshold: 0.7,
            scaleDownThreshold: 0.2,
        );

        // util = 0.5 / 1 = 0.5 → between thresholds → hold at current=1
        self::assertSame(1, $strategy->decide($this->snapshotAt(current: 1, busy: 0.5)));

        // util = 1.4 / 2 = 0.7 → at the boundary, strict > so still in band → hold at 2
        self::assertSame(2, $strategy->decide($this->snapshotAt(current: 2, busy: 1.4)));

        // util = 1.5 / 2 = 0.75 > 0.7 → scale up; ceil(1.5 / 0.7) = 3
        self::assertSame(3, $strategy->decide($this->snapshotAt(current: 2, busy: 1.5)));

        // util = 0.3 / 2 = 0.15 < 0.2 → scale down; ceil(0.3 / 0.7) = 1
        self::assertSame(1, $strategy->decide($this->snapshotAt(current: 2, busy: 0.3)));
    }

    private function snapshot(float $busy): PoolSnapshot
    {
        // Existing tests assume busy >> currentWorkers (saturated regime),
        // which keeps `util` above the scale-up threshold.
        return $this->snapshotAt(current: 5, busy: $busy);
    }

    private function snapshotAt(int $current, float $busy): PoolSnapshot
    {
        return new PoolSnapshot(
            consumer: 'async',
            transports: ['async'],
            currentWorkers: $current,
            busyWorkers: $busy,
            idleWorkers: max(0.0, $current - $busy),
            throughputPerSecond: 0.0,
            throughputByTransport: ['async' => 0.0],
            queueDepth: null,
            min: 1,
            max: 100,
            secondsSinceLastScaleUp: 100.0,
            secondsSinceLastScaleDown: 100.0,
            recentFailureCount: 0,
        );
    }
}
