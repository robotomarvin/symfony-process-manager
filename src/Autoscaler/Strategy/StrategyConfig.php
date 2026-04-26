<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

final readonly class StrategyConfig
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $type,
        public array $params = [],
        public ?string $serviceId = null,
    ) {}

    public static function fixed(int $count): self
    {
        return new self(type: 'fixed', params: ['count' => $count]);
    }

    public static function utilization(float $target = 0.7): self
    {
        return new self(type: 'utilization', params: ['target' => $target]);
    }

    public static function service(string $serviceId): self
    {
        return new self(type: 'service', serviceId: $serviceId);
    }
}
