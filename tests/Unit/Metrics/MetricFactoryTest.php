<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Metrics\Counter;
use SymfonyProcessManager\Metrics\Gauge;
use SymfonyProcessManager\Metrics\MetricFactory;

#[CoversClass(MetricFactory::class)]
final class MetricFactoryTest extends TestCase
{
    public function testCreateCounter(): void
    {
        $factory = new MetricFactory();

        $counter = $factory->createCounter('requests', 'Total requests');

        self::assertInstanceOf(Counter::class, $counter);
        self::assertSame('requests', $counter->getName());
        self::assertSame('Total requests', $counter->getHelp());
    }

    public function testCreateGauge(): void
    {
        $factory = new MetricFactory();

        $gauge = $factory->createGauge('up', 'Is up');

        self::assertInstanceOf(Gauge::class, $gauge);
        self::assertSame('up', $gauge->getName());
        self::assertSame('Is up', $gauge->getHelp());
    }
}
