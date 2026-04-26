<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;
use SymfonyProcessManager\Ipc\Message\WorkerStartedHandlingMessage;
use SymfonyProcessManager\Ipc\WorkerContext;
use SymfonyProcessManager\Ipc\WorkerContextInterface;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\ShutdownReason;
use SymfonyProcessManager\ProcessManager\ShutdownState;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Tests\Support\AutoAdvancingClock;
use SymfonyProcessManager\Tests\Support\FakeLoop;
use SymfonyProcessManager\Transport\ConsumeArgs;
use SymfonyProcessManager\Transport\TransportConfig;
use SymfonyProcessManager\Worker\WorkerProcessFactoryInterface;

#[CoversClass(ProcessManagerLoop::class)]
final class ProcessManagerLoopTest extends TestCase
{
    private AutoAdvancingClock $clock;
    private FakeLoop $reactLoop;
    private ShutdownState $shutdownState;
    private WorkerOutputHandler $outputHandler;
    private ArrayLogger $logger;
    private MetricsRegistry $metrics;
    private IpcFanout $ipcFanout;
    private WorkerContextInterface $workerContext;

    protected function setUp(): void
    {
        $this->clock = new AutoAdvancingClock(1704067200.0, 1.0);
        $this->reactLoop = new FakeLoop();
        $this->shutdownState = new ShutdownState($this->reactLoop, $this->clock);
        $this->logger = new ArrayLogger();
        $this->metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());

        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stderr);

        $this->outputHandler = new WorkerOutputHandler(
            new WorkerOutputFormatter(),
            new IpcCodec(),
            $stdout,
            $stderr,
        );
        $this->ipcFanout = new IpcFanout(new IpcCodec(), new NullLogger());
        $this->workerContext = new WorkerContext();
    }

    public function testSingleWorkerFailureLimitTriggersShutdown(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $exitCode = $this->runTicksUntilDone($loop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));
        self::assertTrue($this->logger->hasMessage('Process manager shutting down.'));
        self::assertSame(4, $factory->getCreateCount());
    }

    public function testCleanExitRestartsImmediately(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(0));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $exitCode = $this->runTicksUntilDone($loop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker restarting after expected exit.'));
        self::assertSame(5, $factory->getCreateCount());
    }

    public function testCleanExitClearsFailureHistory(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(0));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $exitCode = $this->runTicksUntilDone($loop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(7, $factory->getCreateCount());
    }

    public function testNonZeroExitTriggersExponentialBackoff(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $delays = $this->logger->getDelaySeconds();
        self::assertSame([1, 2, 4], $delays);
    }

    public function testMultipleWorkersAreCreated(): void
    {
        $factory = new FakeProcessFactory();
        for ($i = 0; $i < 20; $i++) {
            $factory->addProcess($this->createExitedProcess(1));
        }

        $loop = $this->createLoop($factory, $this->buildPools(processes: 3));

        $exitCode = $this->runTicksUntilDone($loop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));

        $startedWorkerIds = $this->logger->getStartedWorkerIds();
        self::assertContains(1, $startedWorkerIds);
        self::assertContains(2, $startedWorkerIds);
        self::assertContains(3, $startedWorkerIds);
    }

    public function testWorkerExitLogIncludesExitCodeAndPid(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $exitRecord = $this->logger->findRecord('Worker exited.');
        self::assertNotNull($exitRecord);
        self::assertArrayHasKey('exit_code', $exitRecord['context']);
        self::assertSame(1, $exitRecord['context']['exit_code']);
        self::assertArrayHasKey('worker', $exitRecord['context']);
    }

    public function testFactoryReceivesConfiguredTransportAndConsumeArgs(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $consumeArgs = ConsumeArgs::create(memoryLimit: 128, timeLimit: 300, limit: 50);
        $loop = $this->createLoop($factory, $this->buildPools(processes: 1, consumeArgs: $consumeArgs));

        $this->runTicksUntilDone($loop);

        $calls = $factory->getCreateCalls();
        self::assertNotEmpty($calls);
        self::assertSame('async', $calls[0]['transport']);
        $args = $calls[0]['consumeArgs'];
        self::assertSame(128, $args->memoryLimit);
        self::assertSame(300, $args->timeLimit);
        self::assertSame(50, $args->limit);
    }

    public function testNullExitCodeTreatedAsFailure(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $exitCode = $this->runTicksUntilDone($loop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));
    }

    public function testShutdownReasonIsFailureLimitOnExcessiveFailures(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $shutdownRecord = $this->logger->findRecord('Process manager shutting down.');
        self::assertNotNull($shutdownRecord);
        self::assertSame('failure_limit', $shutdownRecord['context']['reason']);
    }

    public function testWorkerStartLogIncludesPid(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $startRecord = $this->logger->findRecord('Worker started.');
        self::assertNotNull($startRecord);
        self::assertArrayHasKey('pid', $startRecord['context']);
        self::assertArrayHasKey('worker', $startRecord['context']);
    }

    public function testStartRegistersPeriodicTimerWithCorrectInterval(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1, pollIntervalMs: 200));
        $loop->start();

        self::assertSame(0.2, $this->reactLoop->getPeriodicTimerInterval());
    }

    public function testWorkerStartIncrementsStartCounter(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $output = $this->metrics->toPrometheusText();
        self::assertStringContainsString('worker_starts_total{transport="async"}', $output);
    }

    public function testWorkerFailureIncrementsFailureCounter(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $output = $this->metrics->toPrometheusText();
        self::assertStringContainsString('worker_failures_total{transport="async"}', $output);
    }

    public function testWorkerBackoffIncrementsBackoffCounter(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $output = $this->metrics->toPrometheusText();
        self::assertStringContainsString('worker_backoffs_total{transport="async"}', $output);
    }

    public function testWorkerExitIncrementsExitCounterWithExitCodeLabel(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $output = $this->metrics->toPrometheusText();
        self::assertStringContainsString('worker_exits_total{exit_code="1"}', $output);
    }

    public function testRunningGaugeIsSetDuringTick(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $this->runTicksUntilDone($loop);

        $output = $this->metrics->toPrometheusText();
        self::assertStringContainsString('process_manager_running', $output);
    }

    public function testHandledProcessedCommandMessageIncrementsMessagesCounter(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->method('getPid')->willReturn(12345);
        $process->method('start')->willReturnCallback(function (?callable $callback = null): void {});

        $factory = new FakeProcessFactory();
        $factory->addProcess($process);

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $loop->tick();

        $codec = new IpcCodec();
        $encodedLine = $codec->encode(new ProcessedCommandMessage('handled', 'App\Message\TestMessage'));
        $this->outputHandler->handleOutput(1, Process::OUT, $encodedLine . "\n");

        $loop->tick();

        $output = $this->metrics->toPrometheusText();
        self::assertStringContainsString('messages_processed_total{transport="async"} 1', $output);
    }

    public function testFailedProcessedCommandMessageDoesNotIncrementMessagesCounter(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->method('getPid')->willReturn(12345);
        $process->method('start')->willReturnCallback(function (?callable $callback = null): void {});

        $factory = new FakeProcessFactory();
        $factory->addProcess($process);

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));

        $loop->tick();

        $codec = new IpcCodec();
        $encodedLine = $codec->encode(new ProcessedCommandMessage('failed', 'App\Message\TestMessage', 'Something went wrong'));
        $this->outputHandler->handleOutput(1, Process::OUT, $encodedLine . "\n");

        $loop->tick();

        $output = $this->metrics->toPrometheusText();
        self::assertStringNotContainsString('messages_processed_total', $output);
    }

    public function testStartedHandlingMessageMarksWorkerBusyAndProcessedMarksIdle(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->method('getPid')->willReturn(12345);
        $process->method('start')->willReturnCallback(function (?callable $callback = null): void {});

        $factory = new FakeProcessFactory();
        $factory->addProcess($process);

        $pools = $this->buildPools(processes: 1);
        $loop = $this->createLoop($factory, $pools);

        $loop->tick();
        self::assertSame(0, $pools[0]->busyWorkerCount());

        $codec = new IpcCodec();
        $busy = $codec->encode(new WorkerStartedHandlingMessage('App\Foo'));
        $this->outputHandler->handleOutput(1, Process::OUT, $busy . "\n");
        $loop->tick();

        self::assertSame(1, $pools[0]->busyWorkerCount());

        $done = $codec->encode(new ProcessedCommandMessage('handled', 'App\Foo'));
        $this->outputHandler->handleOutput(1, Process::OUT, $done . "\n");
        $loop->tick();

        self::assertSame(0, $pools[0]->busyWorkerCount());
    }

    public function testSigkillSentAfterShutdownTimeout(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createRunningProcessThatExitsOnSigkill());

        $loop = $this->createLoop(
            $factory,
            $this->buildPools(processes: 1),
            shutdownTimeoutSeconds: 2,
        );

        $exitCode = $this->runTicksWithShutdownAt($loop, shutdownAtTick: 1);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Sent SIGTERM to worker.'));
        self::assertTrue($this->logger->hasMessage('Sent SIGKILL to worker after shutdown timeout.'));
        self::assertStringContainsString('worker_sigkills_total', $this->metrics->toPrometheusText());
    }

    public function testNoSigkillWhenWorkersExitWithinTimeout(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createRunningProcessThatExitsOnSigterm());

        $loop = $this->createLoop(
            $factory,
            $this->buildPools(processes: 1),
            shutdownTimeoutSeconds: 30,
        );

        $exitCode = $this->runTicksWithShutdownAt($loop, shutdownAtTick: 1);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Sent SIGTERM to worker.'));
        self::assertFalse($this->logger->hasMessage('Sent SIGKILL to worker after shutdown timeout.'));
        self::assertStringNotContainsString('worker_sigkills_total', $this->metrics->toPrometheusText());
    }

    public function testNoSigkillWhenTimeoutIsNull(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createPermanentlyStubbornProcess());

        $loop = $this->createLoop(
            $factory,
            $this->buildPools(processes: 1),
            shutdownTimeoutSeconds: null,
        );

        $this->runTicksWithShutdownAt($loop, shutdownAtTick: 1, maxTicks: 50, expectTermination: false);

        self::assertFalse($this->logger->hasMessage('Sent SIGKILL to worker after shutdown timeout.'));
        self::assertStringNotContainsString('worker_sigkills_total', $this->metrics->toPrometheusText());
    }

    public function testSigkillSentToMultipleRunningWorkers(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createRunningProcessThatExitsOnSigkill());
        $factory->addProcess($this->createRunningProcessThatExitsOnSigterm());
        $factory->addProcess($this->createRunningProcessThatExitsOnSigkill());

        $loop = $this->createLoop(
            $factory,
            $this->buildPools(processes: 3),
            shutdownTimeoutSeconds: 2,
        );

        $exitCode = $this->runTicksWithShutdownAt($loop, shutdownAtTick: 1);

        self::assertSame(Command::SUCCESS, $exitCode);

        $sigkillRecords = array_filter(
            $this->logger->getRecords(),
            static fn(array $r): bool => $r['message'] === 'Sent SIGKILL to worker after shutdown timeout.',
        );
        self::assertCount(2, $sigkillRecords, 'SIGKILL should be sent only to workers still running');
    }

    public function testSigkillSentOnlyOnce(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createPermanentlyStubbornProcess());

        $loop = $this->createLoop(
            $factory,
            $this->buildPools(processes: 1),
            shutdownTimeoutSeconds: 1,
        );

        $this->runTicksWithShutdownAt($loop, shutdownAtTick: 1, maxTicks: 20, expectTermination: false);

        $sigkillRecords = array_filter(
            $this->logger->getRecords(),
            static fn(array $r): bool => $r['message'] === 'Sent SIGKILL to worker after shutdown timeout.',
        );
        self::assertCount(1, $sigkillRecords, 'SIGKILL must only be sent once even when worker ignores it');
    }

    public function testStartLogsTotalWorkerCountAndTransports(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = $this->createLoop($factory, $this->buildPools(processes: 1));
        $loop->start();

        $startRecord = $this->logger->findRecord('Process manager server started.');
        self::assertNotNull($startRecord);
        self::assertSame(1, $startRecord['context']['workers']);
    }

    /**
     * @param list<WorkerPool> $pools
     */
    private function createLoop(
        WorkerProcessFactoryInterface $factory,
        array $pools,
        ?int $shutdownTimeoutSeconds = 30,
    ): ProcessManagerLoop {
        return new ProcessManagerLoop(
            $this->reactLoop,
            $this->shutdownState,
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            $pools,
            $this->metrics,
            $this->ipcFanout,
            $this->workerContext,
            $shutdownTimeoutSeconds,
        );
    }

    /**
     * @return list<WorkerPool>
     */
    private function buildPools(
        int $processes,
        int $pollIntervalMs = 200,
        ?ConsumeArgs $consumeArgs = null,
    ): array {
        $config = TransportConfig::create(
            transport: 'async',
            failureLimit: 3,
            failureWindowSeconds: 60,
            backoffBaseSeconds: 1,
            backoffMaxSeconds: 30,
            pollIntervalMs: $pollIntervalMs,
            consumeArgs: $consumeArgs,
            autoscaler: AutoscalerConfig::legacyFixed($processes),
        );

        return [new WorkerPool($config, 1)];
    }

    private function runTicksUntilDone(ProcessManagerLoop $loop, int $maxTicks = 100): int
    {
        for ($i = 0; $i < $maxTicks; $i++) {
            $result = $loop->tick();

            if ($result !== null) {
                return $result;
            }
        }

        self::fail('Loop did not terminate within ' . $maxTicks . ' ticks');
    }

    private function createExitedProcess(?int $exitCode): Process
    {
        $mock = $this->createMock(Process::class);
        $mock->method('isRunning')->willReturn(false);
        $mock->method('getExitCode')->willReturn($exitCode);
        $mock->method('getPid')->willReturn(random_int(1000, 99999));
        $mock->method('start')->willReturnCallback(function (?callable $callback = null): void {});

        return $mock;
    }

    private function runTicksWithShutdownAt(
        ProcessManagerLoop $loop,
        int $shutdownAtTick,
        int $maxTicks = 100,
        bool $expectTermination = true,
    ): ?int {
        for ($i = 0; $i < $maxTicks; $i++) {
            if ($i === $shutdownAtTick) {
                $this->shutdownState->request(
                    ShutdownReason::SIGNAL,
                    (float) $this->clock->now()->format('U.u'),
                );
            }

            $result = $loop->tick();

            if ($result !== null) {
                return $result;
            }
        }

        if ($expectTermination) {
            self::fail('Loop did not terminate within ' . $maxTicks . ' ticks');
        }

        return null;
    }

    private function createRunningProcessThatExitsOnSigkill(): Process
    {
        $state = new \ArrayObject(['running' => true]);
        $mock = $this->createMock(Process::class);
        $mock->method('isRunning')->willReturnCallback(static fn(): bool => (bool) $state['running']);
        $mock->method('signal')->willReturnCallback(static function (int $signal) use ($state, $mock): Process {
            if ($signal === SIGKILL) {
                $state['running'] = false;
            }

            return $mock;
        });
        $mock->method('getExitCode')->willReturn(137);
        $mock->method('getPid')->willReturn(random_int(1000, 99999));
        $mock->method('start')->willReturnCallback(static function (?callable $callback = null): void {});

        return $mock;
    }

    private function createRunningProcessThatExitsOnSigterm(): Process
    {
        $state = new \ArrayObject(['running' => true]);
        $mock = $this->createMock(Process::class);
        $mock->method('isRunning')->willReturnCallback(static fn(): bool => (bool) $state['running']);
        $mock->method('signal')->willReturnCallback(static function (int $signal) use ($state, $mock): Process {
            if ($signal === SIGTERM) {
                $state['running'] = false;
            }

            return $mock;
        });
        $mock->method('getExitCode')->willReturn(0);
        $mock->method('getPid')->willReturn(random_int(1000, 99999));
        $mock->method('start')->willReturnCallback(static function (?callable $callback = null): void {});

        return $mock;
    }

    private function createPermanentlyStubbornProcess(): Process
    {
        $mock = $this->createMock(Process::class);
        $mock->method('isRunning')->willReturn(true);
        $mock->method('signal')->willReturnSelf();
        $mock->method('getExitCode')->willReturn(null);
        $mock->method('getPid')->willReturn(random_int(1000, 99999));
        $mock->method('start')->willReturnCallback(static function (?callable $callback = null): void {});

        return $mock;
    }
}

/**
 * A process factory that returns pre-configured mock processes.
 */
final class FakeProcessFactory implements WorkerProcessFactoryInterface
{
    /** @var list<Process> */
    private array $processes = [];

    private int $index = 0;
    private int $createCount = 0;

    /** @var list<array{transport: string, consumeArgs: ConsumeArgs}> */
    private array $createCalls = [];

    public function addProcess(Process $process): void
    {
        $this->processes[] = $process;
    }

    public function create(string $transport, ConsumeArgs $consumeArgs): Process
    {
        $this->createCalls[] = [
            'transport' => $transport,
            'consumeArgs' => $consumeArgs,
        ];

        if ($this->index >= count($this->processes)) {
            throw new \RuntimeException(sprintf(
                'FakeProcessFactory: no more processes available (requested index %d, have %d)',
                $this->index,
                count($this->processes),
            ));
        }

        $process = $this->processes[$this->index];
        $this->index++;
        $this->createCount++;

        return $process;
    }

    public function getCreateCount(): int
    {
        return $this->createCount;
    }

    /**
     * @return list<array{transport: string, consumeArgs: ConsumeArgs}>
     */
    public function getCreateCalls(): array
    {
        return $this->createCalls;
    }
}

/**
 * Logger that captures log records for assertions.
 */
final class ArrayLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

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
            'level' => \is_string($level) ? $level : (\is_object($level) && method_exists($level, '__toString') ? (string) $level : 'unknown'),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasMessage(string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}|null
     */
    public function findRecord(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function getStartedWorkerIds(): array
    {
        $ids = [];

        foreach ($this->records as $record) {
            if ($record['message'] === 'Worker started.' && isset($record['context']['worker'])) {
                /** @var int $workerId */
                $workerId = $record['context']['worker'];
                $ids[] = $workerId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    public function getDelaySeconds(): array
    {
        $delays = [];

        foreach ($this->records as $record) {
            if ($record['message'] === 'Worker restarting after unexpected exit.'
                && isset($record['context']['delay_seconds'])) {
                /** @var int $delay */
                $delay = $record['context']['delay_seconds'];
                $delays[] = $delay;
            }
        }

        return $delays;
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }
}
