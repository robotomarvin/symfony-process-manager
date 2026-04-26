<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SymfonyProcessManager\Autoscaler\PoolSnapshot;
use SymfonyProcessManager\Autoscaler\Strategy\FixedStrategy;
use SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Autoscaler\Strategy\UtilizationStrategy;

#[CoversClass(StrategyRegistry::class)]
final class StrategyRegistryTest extends TestCase
{
    public function testBuildsFixedStrategy(): void
    {
        $registry = new StrategyRegistry($this->emptyContainer());
        $strategy = $registry->build(StrategyConfig::fixed(4));

        self::assertInstanceOf(FixedStrategy::class, $strategy);
    }

    public function testBuildsUtilizationStrategyWithDefault(): void
    {
        $registry = new StrategyRegistry($this->emptyContainer());
        $strategy = $registry->build(new StrategyConfig(type: 'utilization', params: []));

        self::assertInstanceOf(UtilizationStrategy::class, $strategy);
    }

    public function testBuildsUtilizationStrategyWithCustomTarget(): void
    {
        $registry = new StrategyRegistry($this->emptyContainer());
        $strategy = $registry->build(StrategyConfig::utilization(0.5));

        self::assertInstanceOf(UtilizationStrategy::class, $strategy);
    }

    public function testBuildsServiceStrategy(): void
    {
        $custom = new class implements ScalingStrategyInterface {
            public function decide(PoolSnapshot $snapshot): int
            {
                return 42;
            }
        };

        $container = new class ($custom) implements ContainerInterface {
            public function __construct(private readonly object $service) {}

            public function get(string $id): object
            {
                return $this->service;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $registry = new StrategyRegistry($container);
        $strategy = $registry->build(StrategyConfig::service('app.custom'));

        self::assertSame($custom, $strategy);
    }

    public function testServiceStrategyMissingIdThrows(): void
    {
        $registry = new StrategyRegistry($this->emptyContainer());

        $this->expectException(\InvalidArgumentException::class);

        $registry->build(new StrategyConfig(type: 'service', serviceId: null));
    }

    public function testServiceMustImplementInterface(): void
    {
        $bad = new \stdClass();

        $container = new class ($bad) implements ContainerInterface {
            public function __construct(private readonly object $service) {}

            public function get(string $id): object
            {
                return $this->service;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $registry = new StrategyRegistry($container);

        $this->expectException(\InvalidArgumentException::class);

        $registry->build(StrategyConfig::service('app.bad'));
    }

    public function testUnknownTypeThrows(): void
    {
        $registry = new StrategyRegistry($this->emptyContainer());

        $this->expectException(\InvalidArgumentException::class);

        $registry->build(new StrategyConfig(type: 'magic'));
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): never
            {
                throw new \RuntimeException('not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
