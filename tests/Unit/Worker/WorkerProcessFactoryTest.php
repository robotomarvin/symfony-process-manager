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
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new WorkerProcessFactory($kernel);
        $consumeArgs = ConsumeArgs::create();

        $process = $factory->create('async', $consumeArgs);
        $commandLine = $process->getCommandLine();

        self::assertStringContainsString('messenger:consume', $commandLine);
        self::assertStringContainsString('async', $commandLine);
    }

    public function testCreateIncludesConsumeArgsInCommand(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new WorkerProcessFactory($kernel);
        $consumeArgs = ConsumeArgs::create(memoryLimit: 128, timeLimit: 60);

        $process = $factory->create('failed', $consumeArgs);
        $commandLine = $process->getCommandLine();

        self::assertStringContainsString('--memory-limit', $commandLine);
        self::assertStringContainsString('128', $commandLine);
        self::assertStringContainsString('--time-limit', $commandLine);
        self::assertStringContainsString('60', $commandLine);
    }

    public function testCreateSetsProjectDirAsWorkingDirectory(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new WorkerProcessFactory($kernel);
        $consumeArgs = ConsumeArgs::create();

        $process = $factory->create('async', $consumeArgs);

        self::assertSame('/app', $process->getWorkingDirectory());
    }

    public function testCreateSetsNullTimeouts(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new WorkerProcessFactory($kernel);
        $consumeArgs = ConsumeArgs::create();

        $process = $factory->create('async', $consumeArgs);

        self::assertNull($process->getTimeout());
        self::assertNull($process->getIdleTimeout());
    }
}
