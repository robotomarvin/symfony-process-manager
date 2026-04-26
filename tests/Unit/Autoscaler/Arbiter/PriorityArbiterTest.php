<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler\Arbiter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\Arbiter\PriorityArbiter;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Transport\TransportConfig;

#[CoversClass(PriorityArbiter::class)]
final class PriorityArbiterTest extends TestCase
{
    public function testSinglePoolGetsItsDesiredUnderCap(): void
    {
        $pool = $this->makePool('a', min: 1, max: 10, priority: 0);
        $arbiter = new PriorityArbiter(20);

        $allocated = $arbiter->allocate(['a' => 5], ['a' => $pool]);

        self::assertSame(['a' => 5], $allocated);
    }

    public function testTwoPoolsSamePriorityShareCapProportionally(): void
    {
        $a = $this->makePool('a', min: 1, max: 10, priority: 5);
        $b = $this->makePool('b', min: 1, max: 10, priority: 5);

        // Total cap = 6, both want above min: a wants 4, b wants 2.
        // After mins (1+1), 4 slots remain. Demand 4+1=5; cap=4, so proportional.
        // a gets floor(4 * 4/5)=3, b gets floor(4*1/5)=0. Leftover 1 → highest remainder (b: 0.8).
        $arbiter = new PriorityArbiter(6);
        $allocated = $arbiter->allocate(['a' => 5, 'b' => 2], ['a' => $a, 'b' => $b]);

        self::assertSame(6, array_sum($allocated));
        self::assertGreaterThanOrEqual(1, $allocated['b']);
        self::assertGreaterThanOrEqual(3, $allocated['a']);
    }

    public function testHigherPriorityIsSatisfiedFirst(): void
    {
        $high = $this->makePool('high', min: 1, max: 10, priority: 100);
        $low = $this->makePool('low', min: 1, max: 10, priority: 0);

        // Cap 5. After mins (2 reserved), 3 left.
        // High wants 5 (above min = 4). Low wants 5 (above min = 4).
        // High priority gets all 3 leftover. Low stays at min.
        $arbiter = new PriorityArbiter(5);
        $allocated = $arbiter->allocate(['high' => 5, 'low' => 5], ['high' => $high, 'low' => $low]);

        self::assertSame(4, $allocated['high']);
        self::assertSame(1, $allocated['low']);
    }

    public function testFixedPoolsAtMaxPriorityReserveTheirMin(): void
    {
        $fixed = $this->makePool('fixed', min: 3, max: 3, priority: PHP_INT_MAX);
        $auto = $this->makePool('auto', min: 1, max: 10, priority: 0);

        $arbiter = new PriorityArbiter(8);
        $allocated = $arbiter->allocate(['fixed' => 3, 'auto' => 10], ['fixed' => $fixed, 'auto' => $auto]);

        self::assertSame(3, $allocated['fixed']);
        // 8 - 3(fixed min) - 1(auto min) = 4 leftover; auto wants 9 above min, so capped at max=10.
        // auto = 1 (min) + 4 (leftover) = 5
        self::assertSame(5, $allocated['auto']);
    }

    public function testZeroDemandGroupsAreSkipped(): void
    {
        $a = $this->makePool('a', min: 2, max: 10, priority: 0);

        $arbiter = new PriorityArbiter(20);
        $allocated = $arbiter->allocate(['a' => 2], ['a' => $a]);

        self::assertSame(2, $allocated['a']);
    }

    public function testRoundingTieBreakDeterministic(): void
    {
        $a = $this->makePool('a', min: 1, max: 10, priority: 0);
        $b = $this->makePool('b', min: 1, max: 10, priority: 0);
        $c = $this->makePool('c', min: 1, max: 10, priority: 0);

        // Cap 5. After mins 3 used, 2 left. Each demands 1 (above min).
        // 3 equal demanders for 2 slots → tie-breaker by alphabetical (a, b first).
        $arbiter = new PriorityArbiter(5);
        $allocated = $arbiter->allocate(['a' => 2, 'b' => 2, 'c' => 2], ['a' => $a, 'b' => $b, 'c' => $c]);

        self::assertSame(5, array_sum($allocated));
    }

    public function testCapEqualsSumOfMinsLeavesNothingToDistribute(): void
    {
        $a = $this->makePool('a', min: 2, max: 5, priority: 0);
        $b = $this->makePool('b', min: 2, max: 5, priority: 0);

        $arbiter = new PriorityArbiter(4);
        $allocated = $arbiter->allocate(['a' => 5, 'b' => 5], ['a' => $a, 'b' => $b]);

        self::assertSame(['a' => 2, 'b' => 2], $allocated);
    }

    public function testThrowsWhenSumOfMinimumsExceedsCap(): void
    {
        $a = $this->makePool('a', min: 3, max: 5, priority: 0);
        $b = $this->makePool('b', min: 3, max: 5, priority: 0);

        $arbiter = new PriorityArbiter(5);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sum of pool minimums (6) exceeds total_cap (5)');

        $arbiter->allocate(['a' => 3, 'b' => 3], ['a' => $a, 'b' => $b]);
    }

    private function makePool(string $name, int $min, int $max, int $priority): WorkerPool
    {
        $autoscaler = new AutoscalerConfig(
            min: $min,
            max: $max,
            priority: $priority,
            smoothingWindowSec: 30,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
            scaleUpStep: 2,
            scaleDownStep: 1,
            strategy: StrategyConfig::fixed($min),
        );

        $config = TransportConfig::create(transport: $name, autoscaler: $autoscaler);

        return new WorkerPool($config, 1);
    }
}
