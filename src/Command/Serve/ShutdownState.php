<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

final class ShutdownState
{
    private bool $requested = false;
    private ?ShutdownReason $reason = null;

    public function isRequested(): bool
    {
        return $this->requested;
    }

    public function getReason(): ?ShutdownReason
    {
        return $this->reason;
    }

    public function request(ShutdownReason $reason): void
    {
        if ($this->requested) {
            return;
        }

        $this->requested = true;
        $this->reason = $reason;
    }
}
