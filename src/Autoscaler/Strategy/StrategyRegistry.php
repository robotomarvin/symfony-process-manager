<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Strategy;

use Psr\Container\ContainerInterface;

final class StrategyRegistry
{
    public function __construct(private readonly ContainerInterface $serviceLocator) {}

    public function build(StrategyConfig $config): ScalingStrategyInterface
    {
        return match ($config->type) {
            'fixed' => new FixedStrategy($this->intParam($config->params, 'count')),
            'utilization' => new UtilizationStrategy($this->floatParam($config->params, 'target', 0.7)),
            'service' => $this->resolveService($config->serviceId),
            default => throw new \InvalidArgumentException(sprintf('Unknown strategy type: %s', $config->type)),
        };
    }

    private function resolveService(?string $serviceId): ScalingStrategyInterface
    {
        if ($serviceId === null || $serviceId === '') {
            throw new \InvalidArgumentException('Strategy type "service" requires a serviceId');
        }

        $service = $this->serviceLocator->get($serviceId);

        if (!$service instanceof ScalingStrategyInterface) {
            throw new \InvalidArgumentException(sprintf(
                'Service "%s" must implement %s',
                $serviceId,
                ScalingStrategyInterface::class,
            ));
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $key): int
    {
        $value = $params[$key] ?? null;

        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Strategy param "%s" must be int', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function floatParam(array $params, string $key, float $default): float
    {
        $value = $params[$key] ?? $default;

        if (is_int($value)) {
            return (float) $value;
        }

        if (!is_float($value)) {
            throw new \InvalidArgumentException(sprintf('Strategy param "%s" must be numeric', $key));
        }

        return $value;
    }
}
