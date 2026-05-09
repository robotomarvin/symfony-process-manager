<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use SymfonyProcessManager\Transport\ConsumeArgs;
use SymfonyProcessManager\Worker\WorkerProcessFactory;

#[CoversClass(WorkerProcessFactory::class)]
final class WorkerProcessFactoryTest extends TestCase
{
    public function testCreateReturnsProcessWithCorrectCommand(): void
    {
        $factory = $this->makeFactory();
        $consumeArgs = ConsumeArgs::create();

        $process = $factory->create(['async'], $consumeArgs);
        $commandLine = $process->getCommandLine();

        self::assertStringContainsString('messenger:consume', $commandLine);
        self::assertStringContainsString('async', $commandLine);
    }

    public function testCreateExpandsMultipleTransportsAsPositionalArgs(): void
    {
        $factory = $this->makeFactory();
        $consumeArgs = ConsumeArgs::create(memoryLimit: 128);

        $process = $factory->create(['orders', 'payments'], $consumeArgs);
        $commandLine = $process->getCommandLine();

        $consumePosition = strpos($commandLine, 'messenger:consume');
        $ordersPosition = strpos($commandLine, 'orders');
        $paymentsPosition = strpos($commandLine, 'payments');
        $memoryLimitPosition = strpos($commandLine, '--memory-limit');

        self::assertNotFalse($consumePosition);
        self::assertNotFalse($ordersPosition);
        self::assertNotFalse($paymentsPosition);
        self::assertNotFalse($memoryLimitPosition);

        self::assertGreaterThan($consumePosition, $ordersPosition);
        self::assertGreaterThan($ordersPosition, $paymentsPosition);
        self::assertGreaterThan($paymentsPosition, $memoryLimitPosition);
    }

    public function testCreateIncludesConsumeArgsInCommand(): void
    {
        $factory = $this->makeFactory();
        $consumeArgs = ConsumeArgs::create(memoryLimit: 128, timeLimit: 60);

        $process = $factory->create(['failed'], $consumeArgs);
        $commandLine = $process->getCommandLine();

        self::assertStringContainsString('--memory-limit', $commandLine);
        self::assertStringContainsString('128', $commandLine);
        self::assertStringContainsString('--time-limit', $commandLine);
        self::assertStringContainsString('60', $commandLine);
    }

    public function testCreateSetsProjectDirAsWorkingDirectory(): void
    {
        $factory = $this->makeFactory();
        $consumeArgs = ConsumeArgs::create();

        $process = $factory->create(['async'], $consumeArgs);

        self::assertSame('/app', $process->getWorkingDirectory());
    }

    public function testCreateSetsNullTimeouts(): void
    {
        $factory = $this->makeFactory();
        $consumeArgs = ConsumeArgs::create();

        $process = $factory->create(['async'], $consumeArgs);

        self::assertNull($process->getTimeout());
        self::assertNull($process->getIdleTimeout());
    }

    private function makeFactory(): WorkerProcessFactory
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        return new WorkerProcessFactory($kernel);
    }
}
