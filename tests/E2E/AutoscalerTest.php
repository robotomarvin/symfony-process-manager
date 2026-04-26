<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Autoscaler\Arbiter\PriorityArbiter;
use SymfonyProcessManager\Autoscaler\AutoscalerLoop;
use SymfonyProcessManager\Autoscaler\Strategy\FixedStrategy;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Autoscaler\Strategy\UtilizationStrategy;
use SymfonyProcessManager\Command\ServeCommand;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\ProcessManager\Orchestrator;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Tests\Support\ConsoleProcessRunner;
use SymfonyProcessManager\Tests\Support\ConsoleProcessSession;

#[CoversClass(ServeCommand::class)]
#[CoversClass(Orchestrator::class)]
#[CoversClass(ProcessManagerLoop::class)]
#[CoversClass(HttpServer::class)]
#[CoversClass(AutoscalerLoop::class)]
#[CoversClass(WorkerPool::class)]
#[CoversClass(PriorityArbiter::class)]
#[CoversClass(FixedStrategy::class)]
#[CoversClass(UtilizationStrategy::class)]
#[CoversClass(StrategyRegistry::class)]
final class AutoscalerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->prepareDoctrineTransport();
    }

    public function testAutoscalerScalesUpUnderLoad(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $address = $this->waitForHttpAddress($session);

            // Wait for the initial scalable worker to start.
            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
                5.0,
            );

            // Push enough slow messages that the utilization strategy wants > min workers.
            $this->dispatchScalableMessages(count: 6, sleep: 1.5);

            // Wait for the autoscaler to apply a scale-up decision.
            $scaleUp = $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && ($r['context']['direction'] ?? null) === 'up',
                10.0,
            );
            self::assertGreaterThan(1, $scaleUp['context']['target'] ?? 0);

            // The fast loop spawns the new worker process a tick or two after the
            // autoscaler decision; wait for an additional scalable worker (id > 2).
            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && (int) ($r['context']['worker'] ?? 0) > 2,
                10.0,
            );

            // /metrics should reflect the new worker count and scale-up counter.
            $metrics = $this->fetchMetrics($address);
            self::assertMatchesRegularExpression(
                '/autoscaler_scale_up_total\{transport="scalable"\} \d+/',
                $metrics,
            );
            self::assertMatchesRegularExpression(
                '/autoscaler_target_workers\{transport="scalable"\} ([2-9]|\d{2,})/',
                $metrics,
                'autoscaler_target_workers should be at least 2 after scale-up',
            );

            // At least 2 distinct workers were launched for the scalable transport.
            $startedScalable = array_filter(
                $session->getRecords(),
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
            );
            self::assertGreaterThanOrEqual(2, count($startedScalable));
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testAutoscalerScalesDownAfterIdle(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $address = $this->waitForHttpAddress($session);

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
                5.0,
            );

            // First push: scale up.
            $this->dispatchScalableMessages(count: 4, sleep: 1.0);

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && ($r['context']['direction'] ?? null) === 'up',
                10.0,
            );

            // Second wait: queue drains and workers go idle. The autoscaler should
            // then scale back down (cooldown_down_sec is 2). With scale_down_step=3
            // and busy still > 0 during the first down tick, the pool can take
            // multiple ticks to reach min=1, so wait for target == min.
            $scaleDown = $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && ($r['context']['direction'] ?? null) === 'down'
                    && ($r['context']['target'] ?? null) === 1,
                15.0,
            );
            self::assertSame(1, $scaleDown['context']['target'] ?? null);

            $metrics = $this->fetchMetrics($address);
            self::assertMatchesRegularExpression(
                '/autoscaler_scale_down_total\{transport="scalable"\} \d+/',
                $metrics,
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testAutoscalerHonorsScaleDownCooldownBetweenSteps(): void
    {
        // The fixture's scalable pool is configured with:
        //   scale_down_step: 1, scale_down_cooldown_sec: 8
        // so a pool that reached the max of 3 workers must shed them one at
        // a time, leaving at least ~8 seconds between consecutive scale-down
        // events. This test pushes the pool to its max, lets the queue drain,
        // and verifies that gap.
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForHttpAddress($session);

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
                5.0,
            );

            // Sustained load so the pool climbs all the way to max (3).
            $this->dispatchScalableMessages(count: 10, sleep: 2.0);

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && ($r['context']['target'] ?? null) === 3,
                20.0,
            );

            // Once the queue drains, first scale-down step should be 3 → 2.
            $firstDown = $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && ($r['context']['direction'] ?? null) === 'down',
                30.0,
            );
            $firstDownAt = microtime(true);
            self::assertSame(2, $firstDown['context']['target'] ?? null, 'first scale-down step should be 3 → 2 (scale_down_step=1)');

            // Second scale-down step should be 2 → 1, and must wait for the cooldown.
            $secondDown = $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable'
                    && ($r['context']['direction'] ?? null) === 'down',
                20.0,
            );
            $secondDownAt = microtime(true);
            self::assertSame(1, $secondDown['context']['target'] ?? null, 'second scale-down step should be 2 → 1');

            $cooldownGap = $secondDownAt - $firstDownAt;
            // Fixture sets scale_down_cooldown_sec = 8s. Allow ~1s slack for
            // poll latency in waitForRecord (100ms tick).
            self::assertGreaterThanOrEqual(
                7.0,
                $cooldownGap,
                sprintf('expected ≥7s between scale-down events (cooldown=8s); got %.2fs', $cooldownGap),
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testAutoscalerExposesBusyAndUnmetDemandMetrics(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $address = $this->waitForHttpAddress($session);

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
                5.0,
            );

            $this->dispatchScalableMessages(count: 3, sleep: 1.5);

            // Wait until at least one busy event is emitted by the autoscaler tick.
            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
                10.0,
            );

            $metrics = $this->fetchMetrics($address);
            self::assertMatchesRegularExpression(
                '/worker_busy_workers\{transport="scalable"\} \d+/',
                $metrics,
            );
            self::assertMatchesRegularExpression(
                '/autoscaler_unmet_demand\{transport="scalable"\} \d+/',
                $metrics,
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testFixedTransportDoesNotScale(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $address = $this->waitForHttpAddress($session);

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['transport'] ?? null) === 'async',
                5.0,
            );

            $this->dispatchScalableMessages(count: 3, sleep: 1.0);

            // Give the autoscaler a few cycles to evaluate.
            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Autoscaler adjusted target.'
                    && ($r['context']['transport'] ?? null) === 'scalable',
                10.0,
            );

            // The async pool is a static `processes: 1` pool; it should never scale.
            $metrics = $this->fetchMetrics($address);
            self::assertMatchesRegularExpression(
                '/autoscaler_target_workers\{transport="async"\} 1/',
                $metrics,
            );
            self::assertMatchesRegularExpression(
                '/autoscaler_current_workers\{transport="async"\} 1/',
                $metrics,
            );
            self::assertDoesNotMatchRegularExpression(
                '/autoscaler_scale_up_total\{transport="async"\}/',
                $metrics,
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    private function waitForHttpAddress(ConsoleProcessSession $session): string
    {
        $record = $session->waitForRecord(
            static fn(array $r): bool => $r['message'] === 'HTTP server listening.',
            5.0,
        );

        $address = $record['context']['address'] ?? null;
        self::assertIsString($address);

        return str_replace('tcp://', '', $address);
    }

    private function fetchMetrics(string $address): string
    {
        $url = "http://{$address}/metrics";
        $body = @file_get_contents($url);

        self::assertIsString($body);

        return $body;
    }

    private function dispatchScalableMessages(int $count, float $sleep): void
    {
        $process = new Process([
            PHP_BINARY,
            'tests/Fixtures/app/bin/console',
            'fixture:dispatch-scalable',
            sprintf('--count=%d', $count),
            sprintf('--sleep=%.3f', $sleep),
        ], $this->getProjectRoot(), $this->getFixtureEnv());

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to dispatch scalable fixture messages: ' . $process->getErrorOutput());
        }
    }

    private function stopSessionIfRunning(ConsoleProcessSession $session): void
    {
        if ($session->getProcess()->isRunning()) {
            $session->signal(SIGTERM);
            $session->waitForExit(10.0);
        }
    }

    private function prepareDoctrineTransport(): void
    {
        $dbPath = $this->getFixtureProjectDir() . '/var/test.db';

        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        $process = new Process([
            PHP_BINARY,
            'tests/Fixtures/app/bin/console',
            'messenger:setup-transports',
            '--no-interaction',
        ], $this->getProjectRoot(), $this->getFixtureEnv());

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to setup doctrine messenger transport.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function getFixtureEnv(): array
    {
        return array_replace($_ENV, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
        ]);
    }

    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function getFixtureProjectDir(): string
    {
        return $this->getProjectRoot() . '/tests/Fixtures/app';
    }
}
