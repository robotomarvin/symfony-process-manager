<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use SymfonyProcessManager\Autoscaler\AutoscalerLoop;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\WorkerContext;
use SymfonyProcessManager\Metrics\MessageClassResolver;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\ProcessManager\Orchestrator;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\ShutdownState;
use SymfonyProcessManager\Tests\Support\AutoAdvancingClock;
use SymfonyProcessManager\Tests\Support\FakeLoop;
use SymfonyProcessManager\Worker\WorkerProcessFactoryInterface;
use Symfony\Component\Console\Command\Command;

#[CoversClass(Orchestrator::class)]
final class OrchestratorTest extends TestCase
{
    public function testRunInstallsSignalHandlerAndStartsLoopAndReturnsExitCode(): void
    {
        $reactLoop = new FakeLoop();
        $clock = new AutoAdvancingClock(0.0, 0.0);
        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $shutdown = new ShutdownState($reactLoop, $clock);

        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stderr);

        $pml = new ProcessManagerLoop(
            $reactLoop,
            $shutdown,
            $clock,
            new NullLogger(),
            $this->createMock(WorkerProcessFactoryInterface::class),
            new WorkerOutputHandler(new WorkerOutputFormatter(), new IpcCodec(), $stdout, $stderr),
            [],
            $metrics,
            new IpcFanout(new IpcCodec(), new NullLogger()),
            new WorkerContext(),
            new MessageClassResolver(),
        );

        $autoscaler = new AutoscalerLoop(
            $reactLoop,
            $clock,
            new NullLogger(),
            $metrics,
            new StrategyRegistry($this->emptyContainer()),
            [],
            arbiter: null,
        );

        $http = new HttpServer($reactLoop, new NullLogger(), $metrics, '127.0.0.1', 0);
        $orchestrator = new Orchestrator($reactLoop, $shutdown, $pml, $autoscaler, $http);

        $shutdown->setExitCode(7);
        $exitCode = $orchestrator->run();

        self::assertSame(7, $exitCode);
        self::assertTrue($reactLoop->wasStarted());
        self::assertTrue($reactLoop->hasSignalListener(SIGTERM));
    }

    public function testDefaultExitCodeIsSuccess(): void
    {
        $reactLoop = new FakeLoop();
        $clock = new AutoAdvancingClock(0.0, 0.0);
        $shutdown = new ShutdownState($reactLoop, $clock);
        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());

        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stderr);

        $pml = new ProcessManagerLoop(
            $reactLoop,
            $shutdown,
            $clock,
            new NullLogger(),
            $this->createMock(WorkerProcessFactoryInterface::class),
            new WorkerOutputHandler(new WorkerOutputFormatter(), new IpcCodec(), $stdout, $stderr),
            [],
            $metrics,
            new IpcFanout(new IpcCodec(), new NullLogger()),
            new WorkerContext(),
            new MessageClassResolver(),
        );
        $autoscaler = new AutoscalerLoop($reactLoop, $clock, new NullLogger(), $metrics, new StrategyRegistry($this->emptyContainer()), [], arbiter: null);
        $http = new HttpServer($reactLoop, new NullLogger(), $metrics, '127.0.0.1', 0);

        $orchestrator = new Orchestrator($reactLoop, $shutdown, $pml, $autoscaler, $http);

        self::assertSame(Command::SUCCESS, $orchestrator->run());
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
