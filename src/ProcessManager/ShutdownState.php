<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;

final class ShutdownState
{
    private bool $requested = false;
    private ?ShutdownReason $reason = null;
    private ?float $requestedAt = null;
    private bool $sigkillSent = false;
    private int $exitCode = Command::SUCCESS;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ClockInterface $clock,
    ) {}

    public function install(): void
    {
        $this->loop->addSignal(SIGTERM, function (): void {
            $this->request(ShutdownReason::SIGNAL, (float) $this->clock->now()->format('U.u'));
        });
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    public function getReason(): ?ShutdownReason
    {
        return $this->reason;
    }

    public function getRequestedAt(): ?float
    {
        return $this->requestedAt;
    }

    public function isSigkillSent(): bool
    {
        return $this->sigkillSent;
    }

    public function markSigkillSent(): void
    {
        $this->sigkillSent = true;
    }

    public function setExitCode(int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function request(ShutdownReason $reason, float $now): void
    {
        if ($this->requested) {
            return;
        }

        $this->requested = true;
        $this->reason = $reason;
        $this->requestedAt = $now;
    }
}
