<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\ProcessManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\ProcessManager\WorkerRunState;
use SymfonyProcessManager\ProcessManager\WorkerState;

#[CoversClass(WorkerState::class)]
final class WorkerStateTest extends TestCase
{
    public function testCreateReturnsStateWithCorrectIdNoProcessNotStopped(): void
    {
        $state = WorkerState::create(3);

        self::assertSame(3, $state->id);
        self::assertFalse($state->hasProcess());
        self::assertFalse($state->isRunning());
        self::assertFalse($state->isStopSignalSent());
        self::assertSame(0, $state->getFailureCount());
    }

    public function testShouldStartReturnsTrueImmediatelyAfterCreation(): void
    {
        $state = WorkerState::create(1);

        self::assertTrue($state->shouldStart(1000.0));
    }

    public function testShouldStartReturnsFalseWhenProcessExists(): void
    {
        $state = WorkerState::create(1);
        $state->setProcess($this->createMock(Process::class));

        self::assertFalse($state->shouldStart(1000.0));
    }

    public function testShouldStartReturnsFalseWhenStopped(): void
    {
        $state = WorkerState::create(1);
        $state->markStopped();

        self::assertFalse($state->shouldStart(1000.0));
    }

    public function testShouldStartReturnsFalseWhenNextStartAtIsInTheFuture(): void
    {
        $now = 1000.0;
        $state = WorkerState::create(1);
        $state->scheduleRestart($now, 5.0);

        self::assertFalse($state->shouldStart($now + 4.0));
    }

    public function testShouldStartReturnsTrueWhenNextStartAtEqualsNow(): void
    {
        $now = 1000.0;
        $state = WorkerState::create(1);
        $state->scheduleRestart($now, 5.0);

        self::assertTrue($state->shouldStart($now + 5.0));
    }

    public function testShouldStartReturnsTrueWhenNextStartAtIsInThePast(): void
    {
        $now = 1000.0;
        $state = WorkerState::create(1);
        $state->scheduleRestart($now, 5.0);

        self::assertTrue($state->shouldStart($now + 6.0));
    }

    public function testMarkStoppedPreventsShouldStartFromReturningTrue(): void
    {
        $state = WorkerState::create(1);
        self::assertTrue($state->shouldStart(1000.0));

        $state->markStopped();
        self::assertFalse($state->shouldStart(1000.0));
    }

    public function testGetProcessAssertsWhenNoProcess(): void
    {
        $state = WorkerState::create(1);

        $this->expectException(\AssertionError::class);
        $state->getProcess();
    }

    public function testHasProcessLifecycle(): void
    {
        $state = WorkerState::create(1);
        self::assertFalse($state->hasProcess());

        $process = $this->createMock(Process::class);
        $state->setProcess($process);
        self::assertTrue($state->hasProcess());

        $state->clearProcess();
        self::assertFalse($state->hasProcess());
    }

    public function testIsRunningDelegatesToProcess(): void
    {
        $state = WorkerState::create(1);
        self::assertFalse($state->isRunning());

        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $state->setProcess($process);
        self::assertTrue($state->isRunning());

        $runningProcess = $this->createMock(Process::class);
        $runningProcess->method('isRunning')->willReturn(false);
        $state->setProcess($runningProcess);
        self::assertFalse($state->isRunning());
    }

    public function testRecordFailureIncreasesFailureCount(): void
    {
        $state = WorkerState::create(1);
        self::assertSame(0, $state->getFailureCount());

        $state->recordFailure(1000.0, 60);
        self::assertSame(1, $state->getFailureCount());

        $state->recordFailure(1010.0, 60);
        self::assertSame(2, $state->getFailureCount());

        $state->recordFailure(1020.0, 60);
        self::assertSame(3, $state->getFailureCount());
    }

    public function testRecordFailureFiltersTimestampsOutsideWindow(): void
    {
        $state = WorkerState::create(1);

        $state->recordFailure(1000.0, 60);
        $state->recordFailure(1010.0, 60);
        self::assertSame(2, $state->getFailureCount());

        // Recording at 1070 means the window starts at 1010, so 1000.0 is outside
        $state->recordFailure(1070.0, 60);
        self::assertSame(2, $state->getFailureCount());
    }

    public function testClearFailuresResetsCountToZero(): void
    {
        $state = WorkerState::create(1);

        $state->recordFailure(1000.0, 60);
        $state->recordFailure(1010.0, 60);
        self::assertSame(2, $state->getFailureCount());

        $state->clearFailures();
        self::assertSame(0, $state->getFailureCount());
    }

    public function testScheduleImmediateRestartMakesShouldStartReturnTrue(): void
    {
        $now = 1000.0;
        $state = WorkerState::create(1);

        // Schedule far in the future
        $state->scheduleRestart($now, 999.0);
        self::assertFalse($state->shouldStart($now));

        // Override with immediate restart
        $state->scheduleImmediateRestart($now);
        self::assertTrue($state->shouldStart($now));
    }

    public function testScheduleRestartWithDelayMakesShouldStartFalseUntilTimePasses(): void
    {
        $now = 1000.0;
        $state = WorkerState::create(1);

        $state->scheduleRestart($now, 10.0);

        self::assertFalse($state->shouldStart($now + 9.0));
        self::assertTrue($state->shouldStart($now + 10.0));
        self::assertTrue($state->shouldStart($now + 11.0));
    }

    public function testMarkStartedResetsStopSignalSentFlag(): void
    {
        $state = WorkerState::create(1);

        $state->markStopSignalSent();
        self::assertTrue($state->isStopSignalSent());

        $state->markStarted();
        self::assertFalse($state->isStopSignalSent());
    }

    public function testStopSignalSentLifecycle(): void
    {
        $state = WorkerState::create(1);

        self::assertFalse($state->isStopSignalSent());

        $state->markStopSignalSent();
        self::assertTrue($state->isStopSignalSent());
    }

    public function testInitialRunStateIsIdle(): void
    {
        $state = WorkerState::create(1);

        self::assertSame(WorkerRunState::Idle, $state->getRunState());
        self::assertFalse($state->isBusy());
    }

    public function testMarkBusyAndIdleTransitions(): void
    {
        $state = WorkerState::create(1);

        $state->markBusy();
        self::assertTrue($state->isBusy());
        self::assertSame(WorkerRunState::Busy, $state->getRunState());

        $state->markIdle();
        self::assertFalse($state->isBusy());
    }

    public function testMarkStartedResetsToIdle(): void
    {
        $state = WorkerState::create(1);
        $state->markBusy();

        $state->markStarted();

        self::assertFalse($state->isBusy());
    }

    public function testMarkDrainingMarksStoppedAndDraining(): void
    {
        $state = WorkerState::create(1);

        $state->markDraining();

        self::assertTrue($state->isDraining());
        self::assertFalse($state->shouldStart(0.0));
    }

    public function testLastPongLifecycle(): void
    {
        $state = WorkerState::create(1);
        self::assertNull($state->getLastPongAt());

        $state->setLastPongAt(123.4);
        self::assertSame(123.4, $state->getLastPongAt());
    }
}
