<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\ProcessManager\WorkerRunState;

#[CoversClass(WorkerRunState::class)]
final class WorkerRunStateTest extends TestCase
{
    public function testEnumCases(): void
    {
        self::assertSame('idle', WorkerRunState::Idle->value);
        self::assertSame('busy', WorkerRunState::Busy->value);
    }
}
