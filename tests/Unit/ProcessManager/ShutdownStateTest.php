<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\ProcessManager\ShutdownReason;
use SymfonyProcessManager\ProcessManager\ShutdownState;

#[CoversClass(ShutdownState::class)]
final class ShutdownStateTest extends TestCase
{
    public function testInitialStateIsNotRequested(): void
    {
        $state = new ShutdownState();

        self::assertFalse($state->isRequested());
        self::assertNull($state->getReason());
    }

    public function testRequestSetsRequestedAndReason(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::SIGNAL, 100.0);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
    }

    public function testRequestWithFailureLimitReason(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::FAILURE_LIMIT, 100.0);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::FAILURE_LIMIT, $state->getReason());
    }

    public function testSecondRequestIsIgnored(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::SIGNAL, 100.0);
        $state->request(ShutdownReason::FAILURE_LIMIT, 200.0);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
    }

    public function testRequestRecordsTimestamp(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::SIGNAL, 1234.5);

        self::assertSame(1234.5, $state->getRequestedAt());
    }

    public function testRequestedAtIsNullBeforeRequest(): void
    {
        $state = new ShutdownState();

        self::assertNull($state->getRequestedAt());
    }

    public function testSecondRequestPreservesOriginalTimestamp(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::SIGNAL, 100.0);
        $state->request(ShutdownReason::FAILURE_LIMIT, 500.0);

        self::assertSame(100.0, $state->getRequestedAt());
    }

    public function testSigkillSentLifecycle(): void
    {
        $state = new ShutdownState();

        self::assertFalse($state->isSigkillSent());

        $state->markSigkillSent();

        self::assertTrue($state->isSigkillSent());
    }
}
