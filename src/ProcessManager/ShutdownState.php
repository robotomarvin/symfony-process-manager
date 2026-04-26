<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

final class ShutdownState
{
    private bool $requested = false;
    private ?ShutdownReason $reason = null;
    private ?float $requestedAt = null;
    private bool $sigkillSent = false;

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
