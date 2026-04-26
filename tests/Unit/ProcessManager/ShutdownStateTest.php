<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use SymfonyProcessManager\ProcessManager\ShutdownReason;
use SymfonyProcessManager\ProcessManager\ShutdownState;
use SymfonyProcessManager\Tests\Support\AutoAdvancingClock;
use SymfonyProcessManager\Tests\Support\FakeLoop;

#[CoversClass(ShutdownState::class)]
final class ShutdownStateTest extends TestCase
{
    public function testInitialStateIsNotRequested(): void
    {
        $state = $this->createState();

        self::assertFalse($state->isRequested());
        self::assertNull($state->getReason());
    }

    public function testRequestSetsRequestedAndReason(): void
    {
        $state = $this->createState();

        $state->request(ShutdownReason::SIGNAL, 100.0);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
    }

    public function testRequestWithFailureLimitReason(): void
    {
        $state = $this->createState();

        $state->request(ShutdownReason::FAILURE_LIMIT, 100.0);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::FAILURE_LIMIT, $state->getReason());
    }

    public function testSecondRequestIsIgnored(): void
    {
        $state = $this->createState();

        $state->request(ShutdownReason::SIGNAL, 100.0);
        $state->request(ShutdownReason::FAILURE_LIMIT, 200.0);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
    }

    public function testRequestRecordsTimestamp(): void
    {
        $state = $this->createState();

        $state->request(ShutdownReason::SIGNAL, 1234.5);

        self::assertSame(1234.5, $state->getRequestedAt());
    }

    public function testRequestedAtIsNullBeforeRequest(): void
    {
        $state = $this->createState();

        self::assertNull($state->getRequestedAt());
    }

    public function testSecondRequestPreservesOriginalTimestamp(): void
    {
        $state = $this->createState();

        $state->request(ShutdownReason::SIGNAL, 100.0);
        $state->request(ShutdownReason::FAILURE_LIMIT, 500.0);

        self::assertSame(100.0, $state->getRequestedAt());
    }

    public function testSigkillSentLifecycle(): void
    {
        $state = $this->createState();

        self::assertFalse($state->isSigkillSent());

        $state->markSigkillSent();

        self::assertTrue($state->isSigkillSent());
    }

    public function testInstallRegistersSigtermHandler(): void
    {
        $loop = new FakeLoop();
        $state = new ShutdownState($loop, new AutoAdvancingClock(100.0, 0.0));

        $state->install();

        self::assertTrue($loop->hasSignalListener(SIGTERM));

        $loop->fireSignal(SIGTERM);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
        self::assertSame(100.0, $state->getRequestedAt());
    }

    public function testExitCodeDefaultsToSuccess(): void
    {
        $state = $this->createState();

        self::assertSame(Command::SUCCESS, $state->getExitCode());
    }

    public function testExitCodeIsMutable(): void
    {
        $state = $this->createState();

        $state->setExitCode(42);

        self::assertSame(42, $state->getExitCode());
    }

    private function createState(): ShutdownState
    {
        return new ShutdownState(new FakeLoop(), new AutoAdvancingClock());
    }
}
