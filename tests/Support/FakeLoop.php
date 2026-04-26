<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Minimal LoopInterface fake. Captures registered timers/signals so tests can drive them.
 */
final class FakeLoop implements LoopInterface
{
    private bool $stopped = false;
    private bool $started = false;

    /** @var list<array{interval: float, callback: callable, periodic: bool, timer: TimerInterface}> */
    private array $timers = [];

    /** @var array<int, list<callable>> */
    private array $signalListeners = [];

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = $this->createTimer((float) $interval, false);
        $this->timers[] = ['interval' => (float) $interval, 'callback' => $callback, 'periodic' => false, 'timer' => $timer];

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = $this->createTimer((float) $interval, true);
        $this->timers[] = ['interval' => (float) $interval, 'callback' => $callback, 'periodic' => true, 'timer' => $timer];

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn(array $entry): bool => $entry['timer'] !== $timer,
        ));
    }

    public function futureTick($listener): void {}

    public function addSignal($signal, $listener): void
    {
        $this->signalListeners[$signal][] = $listener;
    }

    public function removeSignal($signal, $listener): void
    {
        if (!isset($this->signalListeners[$signal])) {
            return;
        }

        $this->signalListeners[$signal] = array_values(array_filter(
            $this->signalListeners[$signal],
            static fn(callable $existing): bool => $existing !== $listener,
        ));
    }

    public function addReadStream($stream, $listener): void {}

    public function addWriteStream($stream, $listener): void {}

    public function removeReadStream($stream): void {}

    public function removeWriteStream($stream): void {}

    public function run(): void
    {
        $this->started = true;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function wasStopped(): bool
    {
        return $this->stopped;
    }

    public function wasStarted(): bool
    {
        return $this->started;
    }

    public function getPeriodicTimerInterval(): ?float
    {
        foreach ($this->timers as $entry) {
            if ($entry['periodic']) {
                return $entry['interval'];
            }
        }

        return null;
    }

    /**
     * Run periodic timer callbacks for a fixed iteration count, stopping if stop() is called.
     */
    public function runUntilStopped(int $maxIterations = 200): void
    {
        for ($i = 0; $i < $maxIterations && !$this->stopped; $i++) {
            foreach ($this->timers as $entry) {
                if (!$entry['periodic']) {
                    continue;
                }
                ($entry['callback'])($entry['timer']);
            }
        }
    }

    public function fireSignal(int $signal): void
    {
        if (!isset($this->signalListeners[$signal])) {
            return;
        }

        foreach ($this->signalListeners[$signal] as $listener) {
            $listener($signal);
        }
    }

    public function hasSignalListener(int $signal): bool
    {
        return !empty($this->signalListeners[$signal]);
    }

    private function createTimer(float $interval, bool $periodic): TimerInterface
    {
        return new class ($interval, $periodic) implements TimerInterface {
            public function __construct(private readonly float $interval, private readonly bool $periodic) {}

            public function getInterval(): float
            {
                return $this->interval;
            }

            public function isPeriodic(): bool
            {
                return $this->periodic;
            }

            public function getCallback(): callable
            {
                return static function (): void {};
            }
        };
    }
}
