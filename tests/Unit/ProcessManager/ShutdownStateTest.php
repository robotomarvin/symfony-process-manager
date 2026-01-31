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

        $state->request(ShutdownReason::SIGNAL);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
    }

    public function testRequestWithFailureLimitReason(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::FAILURE_LIMIT);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::FAILURE_LIMIT, $state->getReason());
    }

    public function testSecondRequestIsIgnored(): void
    {
        $state = new ShutdownState();

        $state->request(ShutdownReason::SIGNAL);
        $state->request(ShutdownReason::FAILURE_LIMIT);

        self::assertTrue($state->isRequested());
        self::assertSame(ShutdownReason::SIGNAL, $state->getReason());
    }
}
