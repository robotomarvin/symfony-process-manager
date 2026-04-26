<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;
use SymfonyProcessManager\Transport\ConsumeArgs;
use Psr\Log\NullLogger;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;
use SymfonyProcessManager\Ipc\WorkerContext;
use SymfonyProcessManager\Ipc\WorkerContextInterface;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\ShutdownReason;
use SymfonyProcessManager\ProcessManager\ShutdownState;
use SymfonyProcessManager\Worker\WorkerProcessFactoryInterface;

#[CoversClass(ProcessManagerLoop::class)]
final class ProcessManagerLoopTest extends TestCase
{
    private AutoAdvancingClock $clock;
    private WorkerOutputHandler $outputHandler;
    private ArrayLogger $logger;
    private MetricsRegistry $metrics;
    private IpcFanout $ipcFanout;
    private WorkerContextInterface $workerContext;

    protected function setUp(): void
    {
        $this->clock = new AutoAdvancingClock(1704067200.0, 1.0);
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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(processes: 3),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $exitCode = $this->runTicksUntilDone($loop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));

        $startedWorkerIds = $this->logger->getStartedWorkerIds();
        self::assertContains(1, $startedWorkerIds);
        self::assertContains(2, $startedWorkerIds);
        self::assertContains(3, $startedWorkerIds);
    }

    public function testProcessManagerStartLogIncludesWorkerCount(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $this->runTicksUntilDone($loop);

        $startRecord = $this->logger->findRecord('Process manager server started.');
        self::assertNotNull($startRecord);
        self::assertSame(1, $startRecord['context']['workers']);
    }

    public function testWorkerExitLogIncludesExitCodeAndPid(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(consumeArgs: [
                'memory_limit' => 128,
                'time_limit' => 300,
                'limit' => 50,
                'sleep' => null,
                'queues' => [],
                'extra' => [],
            ]),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $this->runTicksUntilDone($loop);

        $calls = $factory->getCreateCalls();
        self::assertNotEmpty($calls);
        self::assertSame('async', $calls[0]['transport']);
        $consumeArgs = $calls[0]['consumeArgs'];
        self::assertSame(128, $consumeArgs->memoryLimit);
        self::assertSame(300, $consumeArgs->timeLimit);
        self::assertSame(50, $consumeArgs->limit);
    }

    public function testNullExitCodeTreatedAsFailure(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $this->runTicksUntilDone($loop);

        $shutdownRecord = $this->logger->findRecord('Process manager shutting down.');
        self::assertNotNull($shutdownRecord);
        self::assertSame('failure_limit', $shutdownRecord['context']['reason']);
    }

    public function testBackoffDelaysAreExponentialWithMaxCap(): void
    {
        $factory = new FakeProcessFactory();
        for ($i = 0; $i < 20; $i++) {
            $factory->addProcess($this->createExitedProcess(1));
        }

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $this->runTicksUntilDone($loop);

        $delays = $this->logger->getDelaySeconds();
        self::assertSame(1, $delays[0]);
        self::assertSame(2, $delays[1]);
        self::assertSame(4, $delays[2]);
    }

    public function testWorkerStartLogIncludesPid(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $this->runTicksUntilDone($loop);

        $startRecord = $this->logger->findRecord('Worker started.');
        self::assertNotNull($startRecord);
        self::assertArrayHasKey('pid', $startRecord['context']);
        self::assertArrayHasKey('worker', $startRecord['context']);
    }

    public function testRunWithFakeLoopReturnsCorrectExitCode(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $processManagerLoop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $fakeLoop = new FakeLoop();
        $exitCode = $processManagerLoop->run($fakeLoop);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));
        self::assertTrue($fakeLoop->wasStopped());
    }

    public function testRunRegistersPeriodicTimerWithCorrectInterval(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $processManagerLoop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(pollIntervalMs: 200),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $fakeLoop = new FakeLoop();
        $processManagerLoop->run($fakeLoop);

        self::assertSame(0.2, $fakeLoop->getPeriodicTimerInterval());
    }

    public function testWorkerStartIncrementsStartCounter(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $shutdownState = new ShutdownState();
        $workers = $loop->initializeWorkers();

        $loop->tick($workers, $shutdownState);

        $codec = new IpcCodec();
        $encodedLine = $codec->encode(new ProcessedCommandMessage('handled', 'App\Message\TestMessage'));
        $this->outputHandler->handleOutput(1, Process::OUT, $encodedLine . "\n");

        $loop->tick($workers, $shutdownState);

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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
        );

        $shutdownState = new ShutdownState();
        $workers = $loop->initializeWorkers();

        $loop->tick($workers, $shutdownState);

        $codec = new IpcCodec();
        $encodedLine = $codec->encode(new ProcessedCommandMessage('failed', 'App\Message\TestMessage', 'Something went wrong'));
        $this->outputHandler->handleOutput(1, Process::OUT, $encodedLine . "\n");

        $loop->tick($workers, $shutdownState);

        $output = $this->metrics->toPrometheusText();
        self::assertStringNotContainsString('messages_processed_total', $output);
    }

    public function testSigkillSentAfterShutdownTimeout(): void
    {
        $factory = new FakeProcessFactory();
        $factory->addProcess($this->createRunningProcessThatExitsOnSigkill());

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(processes: 3),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
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

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            transportConfigs: self::rawTransportConfigs(),
            metrics: $this->metrics,
            ipcFanout: $this->ipcFanout,
            workerContext: $this->workerContext,
            shutdownTimeoutSeconds: 1,
        );

        $this->runTicksWithShutdownAt($loop, shutdownAtTick: 1, maxTicks: 20, expectTermination: false);

        $sigkillRecords = array_filter(
            $this->logger->getRecords(),
            static fn(array $r): bool => $r['message'] === 'Sent SIGKILL to worker after shutdown timeout.',
        );
        self::assertCount(1, $sigkillRecords, 'SIGKILL must only be sent once even when worker ignores it');
    }

    /**
     * @param array{
     *     memory_limit: int|null,
     *     time_limit: int|null,
     *     limit: int|null,
     *     sleep: int|null,
     *     queues: list<string>,
     *     extra: list<string>,
     * }|null $consumeArgs
     * @return array<string, array{
     *     processes: int,
     *     failure_limit: int,
     *     failure_window: int,
     *     backoff_base: int,
     *     backoff_max: int,
     *     poll_interval_ms: int,
     *     consume_args: array{
     *         memory_limit: int|null,
     *         time_limit: int|null,
     *         limit: int|null,
     *         sleep: int|null,
     *         queues: list<string>,
     *         extra: list<string>,
     *     },
     * }>
     */
    private static function rawTransportConfigs(
        string $transport = 'async',
        int $processes = 1,
        int $failureLimit = 3,
        int $failureWindow = 60,
        int $backoffBase = 1,
        int $backoffMax = 30,
        int $pollIntervalMs = 200,
        ?array $consumeArgs = null,
    ): array {
        return [$transport => [
            'processes' => $processes,
            'failure_limit' => $failureLimit,
            'failure_window' => $failureWindow,
            'backoff_base' => $backoffBase,
            'backoff_max' => $backoffMax,
            'poll_interval_ms' => $pollIntervalMs,
            'consume_args' => $consumeArgs ?? [
                'memory_limit' => null,
                'time_limit' => null,
                'limit' => null,
                'sleep' => null,
                'queues' => [],
                'extra' => [],
            ],
        ]];
    }

    private function runTicksUntilDone(ProcessManagerLoop $loop, int $maxTicks = 100): int
    {
        $shutdownState = new ShutdownState();
        $workers = $loop->initializeWorkers();

        $this->logger->info('Process manager server started.', [
            'workers' => count($workers),
            'transports' => ['async'],
        ]);

        for ($i = 0; $i < $maxTicks; $i++) {
            $result = $loop->tick($workers, $shutdownState);

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
        $mock->method('start')->willReturnCallback(function (?callable $callback = null): void {
            // Do nothing - process immediately exits
        });

        return $mock;
    }

    private function runTicksWithShutdownAt(
        ProcessManagerLoop $loop,
        int $shutdownAtTick,
        int $maxTicks = 100,
        bool $expectTermination = true,
    ): ?int {
        $shutdownState = new ShutdownState();
        $workers = $loop->initializeWorkers();

        for ($i = 0; $i < $maxTicks; $i++) {
            if ($i === $shutdownAtTick) {
                $shutdownState->request(
                    ShutdownReason::SIGNAL,
                    (float) $this->clock->now()->format('U.u'),
                );
            }

            $result = $loop->tick($workers, $shutdownState);

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
 * A clock that advances by a fixed amount on each call to now().
 * This allows the ProcessManagerLoop to progress past backoff delays
 * without actually waiting.
 */
final class AutoAdvancingClock implements ClockInterface
{
    private float $currentTime;

    public function __construct(
        float $startTime,
        private readonly float $advanceSeconds,
    ) {
        $this->currentTime = $startTime;
    }

    public function now(): \DateTimeImmutable
    {
        $time = $this->currentTime;
        $this->currentTime += $this->advanceSeconds;

        $result = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $time));
        assert($result instanceof \DateTimeImmutable);

        return $result;
    }

    public function sleep(float|int $seconds): void
    {
        $this->currentTime += $seconds;
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this;
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
 * A fake ReactPHP event loop for testing run() integration.
 * Captures the periodic timer callback and runs it synchronously until stop() is called.
 */
final class FakeLoop implements LoopInterface
{
    private bool $stopped = false;
    private ?float $periodicTimerInterval = null;

    public function addTimer($interval, $callback): TimerInterface
    {
        return $this->createMockTimer();
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $this->periodicTimerInterval = (float) $interval;
        $timer = $this->createMockTimer();

        $this->stopped = false;
        $this->runTimerCallback($callback, $timer, 200);

        return $timer;
    }

    private function runTimerCallback(callable $callback, TimerInterface $timer, int $maxIterations): void
    {
        for ($i = 0; $i < $maxIterations && !$this->stopped; $i++) {
            $callback($timer);
        }
    }

    public function cancelTimer(TimerInterface $timer): void {}

    public function futureTick($listener): void {}

    public function addSignal($signal, $listener): void {}

    public function removeSignal($signal, $listener): void {}

    public function addReadStream($stream, $listener): void {}

    public function addWriteStream($stream, $listener): void {}

    public function removeReadStream($stream): void {}

    public function removeWriteStream($stream): void {}

    public function run(): void {}

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function wasStopped(): bool
    {
        return $this->stopped;
    }

    public function getPeriodicTimerInterval(): ?float
    {
        return $this->periodicTimerInterval;
    }

    private function createMockTimer(): TimerInterface
    {
        return new class implements TimerInterface {
            public function getInterval(): float
            {
                return 0.0;
            }

            public function isPeriodic(): bool
            {
                return true;
            }

            public function getCallback(): callable
            {
                return static function (): void {};
            }
        };
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
