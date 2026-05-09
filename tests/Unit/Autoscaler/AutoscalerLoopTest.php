<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SymfonyProcessManager\Autoscaler\Arbiter\PriorityArbiter;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Autoscaler\AutoscalerLoop;
use SymfonyProcessManager\Autoscaler\PoolSnapshot;
use SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Consumer\ConsumerConfig;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Tests\Support\AutoAdvancingClock;
use SymfonyProcessManager\Tests\Support\FakeLoop;

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
        $output = $metrics->toPrometheusText();
        self::assertStringContainsString('autoscaler_target_workers{consumer="async"}', $output);
        self::assertStringContainsString('autoscaler_current_workers{consumer="async"}', $output);
    }

    public function testEvaluateUsesArbiterWhenProvided(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $high = $this->makePool(min: 1, max: 10, priority: 100, scaleUpStep: 100, scaleUpCooldownSec: 0, label: 'high');
        $low = $this->makePool(min: 1, max: 10, priority: 0, scaleUpStep: 100, scaleUpCooldownSec: 0, label: 'low');

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

    public function testLogDirectionUpThenDownOverConsecutiveEvaluates(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $logger = new ArrayLogger();
        $stub = new StubStrategy([5, 2]);

        $pool = $this->makePool(
            min: 1,
            max: 10,
            scaleUpStep: 100,
            scaleUpCooldownSec: 0,
            scaleDownStep: 100,
            label: 'async',
            strategy: StrategyConfig::service('stub'),
        );

        $autoscaler = new AutoscalerLoop(
            new FakeLoop(),
            $clock,
            $logger,
            new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory()),
            new StrategyRegistry($this->containerWith(['stub' => $stub])),
            [$pool],
            arbiter: null,
        );
        $autoscaler->start();

        $autoscaler->evaluate();
        $autoscaler->evaluate();

        $infoLogs = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool => $r['level'] === 'info' && $r['message'] === 'Autoscaler adjusted target.',
        ));

        self::assertCount(2, $infoLogs);
        self::assertSame('up', $infoLogs[0]['context']['direction']);
        self::assertSame(1, $infoLogs[0]['context']['previous']);
        self::assertSame(5, $infoLogs[0]['context']['target']);
        self::assertSame('down', $infoLogs[1]['context']['direction']);
        self::assertSame(5, $infoLogs[1]['context']['previous']);
        self::assertSame(2, $infoLogs[1]['context']['target']);
    }

    public function testNoLogEmittedWhenTargetUnchanged(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $logger = new ArrayLogger();

        // Pool fixed at 1, strategy returns the same value → setTarget never triggers
        // either branch, applied=false, logger must stay silent.
        $pool = $this->makePool(min: 1, max: 1, strategy: StrategyConfig::fixed(1));

        $autoscaler = new AutoscalerLoop(
            new FakeLoop(),
            $clock,
            $logger,
            new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory()),
            new StrategyRegistry($this->emptyContainer()),
            [$pool],
            arbiter: null,
        );
        $autoscaler->start();

        $autoscaler->evaluate();
        $autoscaler->evaluate();

        $adjustLogs = array_filter(
            $logger->records,
            static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.',
        );

        self::assertCount(0, $adjustLogs);
    }

    public function testFixedStrategyHoldsTargetSteady(): void
    {
        $clock = new AutoAdvancingClock(1000.0, 0.0);
        $pool = $this->makePool(min: 3, max: 3, scaleUpStep: 100, label: 'async', strategy: StrategyConfig::fixed(3));

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
        int $scaleDownStep = 1,
        string $label = 'async',
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
            scaleDownStep: $scaleDownStep,
            strategy: $strategy ?? StrategyConfig::utilization(0.7),
        );
        $config = ConsumerConfig::create(label: $label, autoscaler: $autoscaler);

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

    /**
     * @param array<string, object> $services
     */
    private function containerWith(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $id): object
            {
                if (!isset($this->services[$id])) {
                    throw new \RuntimeException('not found: ' . $id);
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }
}

/**
 * Strategy returning a pre-seeded sequence of values; last value repeats.
 */
final class StubStrategy implements ScalingStrategyInterface
{
    private int $cursor = 0;

    /** @param list<int> $sequence */
    public function __construct(private readonly array $sequence) {}

    public function decide(PoolSnapshot $snapshot): int
    {
        $idx = min($this->cursor, count($this->sequence) - 1);
        $this->cursor++;

        return $this->sequence[$idx];
    }
}

/**
 * Logger collecting all records for assertion.
 */
final class ArrayLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
