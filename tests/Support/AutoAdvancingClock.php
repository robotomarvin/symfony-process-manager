<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/**
 * A clock that advances by a fixed amount on each call to now().
 */
final class AutoAdvancingClock implements ClockInterface
{
    private float $currentTime;

    public function __construct(
        float $startTime = 1704067200.0,
        private readonly float $advanceSeconds = 1.0,
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
