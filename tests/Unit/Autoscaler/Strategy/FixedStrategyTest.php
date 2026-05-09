<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\PoolSnapshot;
use SymfonyProcessManager\Autoscaler\Strategy\FixedStrategy;

#[CoversClass(FixedStrategy::class)]
final class FixedStrategyTest extends TestCase
{
    public function testReturnsConfiguredCountIgnoringSnapshot(): void
    {
        $strategy = new FixedStrategy(count: 3);

        $snapshot = new PoolSnapshot(
            consumer: 'async',
            transports: ['async'],
            currentWorkers: 0,
            busyWorkers: 50,
            idleWorkers: 0,
            throughputPerSecond: 100.0,
            throughputByTransport: ['async' => 100.0],
            queueDepth: null,
            min: 1,
            max: 10,
            secondsSinceLastScaleUp: 1.0,
            secondsSinceLastScaleDown: 1.0,
            recentFailureCount: 0,
        );

        self::assertSame(3, $strategy->decide($snapshot));
    }

    public function testZeroCountIsAllowed(): void
    {
        $strategy = new FixedStrategy(count: 0);

        $snapshot = new PoolSnapshot(
            consumer: 'a',
            transports: ['a'],
            currentWorkers: 5,
            busyWorkers: 5,
            idleWorkers: 0,
            throughputPerSecond: 0.0,
            throughputByTransport: ['a' => 0.0],
            queueDepth: null,
            min: 0,
            max: 10,
            secondsSinceLastScaleUp: 0.0,
            secondsSinceLastScaleDown: 0.0,
            recentFailureCount: 0,
        );

        self::assertSame(0, $strategy->decide($snapshot));
    }
}
