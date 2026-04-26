<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use SymfonyProcessManager\Autoscaler\Arbiter\PriorityArbiter;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Autoscaler\AutoscalerLoop;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Tests\Support\AutoAdvancingClock;
use SymfonyProcessManager\Tests\Support\FakeLoop;
use SymfonyProcessManager\Transport\TransportConfig;

#[CoversClass(AutoscalerLoop::class)]
final class AutoscalerLoopTest extends TestCase
{
    public function testStartRegistersPeriodicTimer(): void
    {
        $loop = new FakeLoop();
        $autoscaler = new AutoscalerLoop(
            $loop,
            new AutoAdvancingClock(0.0, 1.0),
            new NullLogger(),
            new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory()),
            new StrategyRegistry($this->emptyContainer()),
            [],
            arbiter: null,
            intervalSec: 7,
        );

        $autoscaler->start();

        self::assertSame(7.0, $loop->getPeriodicTimerInterval());
    }

    public function testEvaluateAppliesStrategyTargetWithoutArbiter(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $pool = $this->makePool(min: 1, max: 5, scaleUpStep: 100, scaleUpCooldownSec: 0);

        // Mark all workers busy so utilization strategy will compute > current
        foreach ($pool->workers() as $worker) {
            $worker->markBusy();
        }
        $pool->sample(0.0);
        $pool->sample(1000.0);

        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $autoscaler = new AutoscalerLoop(
            new FakeLoop(),
            $clock,
            new NullLogger(),
            $metrics,
            new StrategyRegistry($this->emptyContainer()),
            [$pool],
            arbiter: null,
        );
        $autoscaler->start();

        $autoscaler->evaluate();

        // 1 busy worker / 0.7 = 2 → pool can step to 5 (max), but starts at 1 → scaleUpStep=100, so jumps.
        self::assertGreaterThan(1, $pool->getTarget());
        self::assertStringContainsString('autoscaler_target_workers', $metrics->toPrometheusText());
    }

    public function testEvaluateUsesArbiterWhenProvided(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $high = $this->makePool(min: 1, max: 10, priority: 100, scaleUpStep: 100, scaleUpCooldownSec: 0, transport: 'high');
        $low = $this->makePool(min: 1, max: 10, priority: 0, scaleUpStep: 100, scaleUpCooldownSec: 0, transport: 'low');

        // Both pools have all workers busy → both want 2.
        $high->workers()[0]->markBusy();
        $low->workers()[0]->markBusy();

        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $autoscaler = new AutoscalerLoop(
            new FakeLoop(),
            $clock,
            new NullLogger(),
            $metrics,
            new StrategyRegistry($this->emptyContainer()),
            [$high, $low],
            arbiter: new PriorityArbiter(2),  // exact min sum, no leftover
        );
        $autoscaler->start();

        $autoscaler->evaluate();

        self::assertSame(1, $high->getTarget());
        self::assertSame(1, $low->getTarget());
        // unmet demand should be tracked
        self::assertStringContainsString('autoscaler_unmet_demand', $metrics->toPrometheusText());
    }

    public function testFixedStrategyHoldsTargetSteady(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $pool = $this->makePool(min: 3, max: 3, scaleUpStep: 100, transport: 'async', strategy: StrategyConfig::fixed(3));

        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $autoscaler = new AutoscalerLoop(
            new FakeLoop(),
            $clock,
            new NullLogger(),
            $metrics,
            new StrategyRegistry($this->emptyContainer()),
            [$pool],
            arbiter: null,
        );
        $autoscaler->start();

        $autoscaler->evaluate();
        $autoscaler->evaluate();

        self::assertSame(3, $pool->getTarget());
        self::assertSame(3, $pool->activeWorkerCount());
    }

    private function makePool(
        int $min,
        int $max,
        int $priority = 0,
        int $scaleUpStep = 2,
        int $scaleUpCooldownSec = 30,
        string $transport = 'async',
        ?StrategyConfig $strategy = null,
    ): WorkerPool {
        $autoscaler = new AutoscalerConfig(
            min: $min,
            max: $max,
            priority: $priority,
            smoothingWindowSec: 1,
            scaleUpCooldownSec: $scaleUpCooldownSec,
            scaleDownCooldownSec: 0,
            scaleUpStep: $scaleUpStep,
            scaleDownStep: 1,
            strategy: $strategy ?? StrategyConfig::utilization(0.7),
        );
        $config = TransportConfig::create(transport: $transport, autoscaler: $autoscaler);

        return new WorkerPool($config, 1);
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): never
            {
                throw new \RuntimeException('not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
