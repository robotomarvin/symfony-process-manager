<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;

#[CoversClass(AutoscalerConfig::class)]
final class AutoscalerConfigTest extends TestCase
{
    public function testConstructAcceptsValidValues(): void
    {
        $config = new AutoscalerConfig(
            min: 1,
            max: 4,
            priority: 0,
            smoothingWindowSec: 30,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
            scaleUpStep: 2,
            scaleDownStep: 1,
            strategy: StrategyConfig::fixed(2),
        );

        self::assertSame(1, $config->min);
        self::assertSame(4, $config->max);
    }

    public function testMinBelowOneFailsAssertion(): void
    {
        $this->expectException(\AssertionError::class);

        new AutoscalerConfig(
            min: 0,
            max: 4,
            priority: 0,
            smoothingWindowSec: 30,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
            scaleUpStep: 1,
            scaleDownStep: 1,
            strategy: StrategyConfig::fixed(0),
        );
    }

    public function testMaxBelowMinFailsAssertion(): void
    {
        $this->expectException(\AssertionError::class);

        new AutoscalerConfig(
            min: 3,
            max: 2,
            priority: 0,
            smoothingWindowSec: 30,
            scaleUpCooldownSec: 30,
            scaleDownCooldownSec: 300,
            scaleUpStep: 1,
            scaleDownStep: 1,
            strategy: StrategyConfig::fixed(2),
        );
    }

    public function testLegacyFixedProducesEqualMinMax(): void
    {
        $config = AutoscalerConfig::legacyFixed(3);

        self::assertSame(3, $config->min);
        self::assertSame(3, $config->max);
        self::assertSame(PHP_INT_MAX, $config->priority);
    }
}
