<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Command\Serve;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Command\Serve\ConsumeArgs;
use SymfonyProcessManager\Command\Serve\ProcessManagerLoop;
use SymfonyProcessManager\Command\Serve\WorkerOutputFormatter;
use SymfonyProcessManager\Command\Serve\WorkerOutputHandler;
use SymfonyProcessManager\Command\Serve\WorkerProcessFactoryInterface;

#[CoversClass(ProcessManagerLoop::class)]
final class ProcessManagerLoopTest extends TestCase
{
    private AutoAdvancingClock $clock;
    private WorkerOutputHandler $outputHandler;
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new AutoAdvancingClock(1704067200.0, 1.0);
        $this->logger = new ArrayLogger();

        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stderr);

        $this->outputHandler = new WorkerOutputHandler(
            new WorkerOutputFormatter(),
            $stdout,
            $stderr,
        );
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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $exitCode = $loop->run();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));
        self::assertTrue($this->logger->hasMessage('Process manager shutting down.'));
        self::assertSame(4, $factory->getCreateCount());
    }

    public function testCleanExitRestartsImmediately(): void
    {
        $factory = new FakeProcessFactory();
        // First process exits cleanly (code 0) -> immediate restart
        $factory->addProcess($this->createExitedProcess(0));
        // Then 4 failures to trigger shutdown
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $exitCode = $loop->run();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker restarting after expected exit.'));
        self::assertSame(5, $factory->getCreateCount());
    }

    public function testCleanExitClearsFailureHistory(): void
    {
        $factory = new FakeProcessFactory();
        // 2 failures
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        // Clean exit clears failures
        $factory->addProcess($this->createExitedProcess(0));
        // 4 more failures needed to reach limit (fresh counter)
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));
        $factory->addProcess($this->createExitedProcess(1));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $exitCode = $loop->run();

        self::assertSame(Command::SUCCESS, $exitCode);
        // Total processes: 2 + 1 + 4 = 7
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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $loop->run();

        $delays = $this->logger->getDelaySeconds();
        self::assertSame([1, 2, 4], $delays);
    }

    public function testMultipleWorkersAreCreated(): void
    {
        $factory = new FakeProcessFactory();
        // 3 workers, each needs 4 failures to trigger shutdown
        // But once shutdown is requested for one worker, others are stopped too
        // Worker 1: 4 failures -> shutdown requested
        // Workers 2 and 3: may have started or be waiting
        for ($i = 0; $i < 20; $i++) {
            $factory->addProcess($this->createExitedProcess(1));
        }

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            workerCount: 3,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $exitCode = $loop->run();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($this->logger->hasMessage('Worker failure limit reached.'));

        // All 3 workers should have started at least once
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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $loop->run();

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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $loop->run();

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

        $consumeArgs = ConsumeArgs::create(
            memoryLimit: 128,
            timeLimit: 300,
            limit: 50,
        );

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            workerCount: 1,
            transport: 'async',
            consumeArgs: $consumeArgs,
        );

        $loop->run();

        $calls = $factory->getCreateCalls();
        self::assertNotEmpty($calls);
        self::assertSame('async', $calls[0]['transport']);
        self::assertSame($consumeArgs, $calls[0]['consumeArgs']);
    }

    public function testNullExitCodeTreatedAsFailure(): void
    {
        $factory = new FakeProcessFactory();
        // Create processes that return null exit code
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));
        $factory->addProcess($this->createExitedProcess(null));

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $factory,
            $this->outputHandler,
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $exitCode = $loop->run();

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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $loop->run();

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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $loop->run();

        $delays = $this->logger->getDelaySeconds();
        // First failure: delay=1, second: delay=2, third: delay=4
        // Fourth failure: >3 failures -> shutdown (no delay logged)
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
            workerCount: 1,
            transport: 'async',
            consumeArgs: ConsumeArgs::create(),
        );

        $loop->run();

        $startRecord = $this->logger->findRecord('Worker started.');
        self::assertNotNull($startRecord);
        self::assertArrayHasKey('pid', $startRecord['context']);
        self::assertArrayHasKey('worker', $startRecord['context']);
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
