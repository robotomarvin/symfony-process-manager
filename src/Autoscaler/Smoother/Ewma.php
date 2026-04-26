<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Smoother;

final class Ewma
{
    private ?float $value = null;

    public function __construct(private readonly float $timeConstantSeconds)
    {
        assert($timeConstantSeconds > 0.0, 'time constant must be positive');
    }

    public function update(float $deltaSeconds, float $sample): void
    {
        if ($this->value === null) {
            $this->value = $sample;
            return;
        }

        if ($deltaSeconds <= 0.0) {
            $this->value = $sample;
            return;
        }

        $alpha = 1.0 - exp(-$deltaSeconds / $this->timeConstantSeconds);
        $this->value = ($alpha * $sample) + ((1.0 - $alpha) * $this->value);
    }

    public function value(): float
    {
        return $this->value ?? 0.0;
    }

    public function hasSample(): bool
    {
        return $this->value !== null;
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
