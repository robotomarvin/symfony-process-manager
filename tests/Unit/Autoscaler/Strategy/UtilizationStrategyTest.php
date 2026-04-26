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

    private function snapshot(int $busy): PoolSnapshot
    {
        return new PoolSnapshot(
            transport: 'async',
            currentWorkers: 5,
            busyWorkers: $busy,
            idleWorkers: max(0, 5 - $busy),
            throughputPerSecond: 0.0,
            queueDepth: null,
            min: 1,
            max: 100,
            secondsSinceLastScaleUp: 100.0,
            secondsSinceLastScaleDown: 100.0,
            recentFailureCount: 0,
        );
    }
}
