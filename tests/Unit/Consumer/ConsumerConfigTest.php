<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Consumer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Consumer\ConsumerConfig;
use SymfonyProcessManager\Transport\ConsumeArgs;

#[CoversClass(ConsumerConfig::class)]
final class ConsumerConfigTest extends TestCase
{
    public function testCreateWithAllParameters(): void
    {
        $consumeArgs = ConsumeArgs::create(memoryLimit: 256);
        $autoscaler = AutoscalerConfig::legacyFixed(4);

        $config = ConsumerConfig::create(
            label: 'ingest',
            transports: ['orders', 'payments'],
            failureLimit: 5,
            failureWindowSeconds: 120,
            backoffBaseSeconds: 2,
            backoffMaxSeconds: 60,
            pollIntervalMs: 500,
            consumeArgs: $consumeArgs,
            autoscaler: $autoscaler,
        );

        self::assertSame('ingest', $config->label);
        self::assertSame(['orders', 'payments'], $config->transports);
        self::assertSame(5, $config->failureLimit);
        self::assertSame(120, $config->failureWindowSeconds);
        self::assertSame(2, $config->backoffBaseSeconds);
        self::assertSame(60, $config->backoffMaxSeconds);
        self::assertSame(500, $config->pollIntervalMs);
        self::assertSame($consumeArgs, $config->consumeArgs);
        self::assertSame($autoscaler, $config->autoscaler);
    }

    public function testCreateWithScalarTransportNormalizedToList(): void
    {
        $config = ConsumerConfig::create(label: 'failed', transports: 'failed');

        self::assertSame(['failed'], $config->transports);
    }

    public function testCreateWithoutTransportsDefaultsToLabel(): void
    {
        $config = ConsumerConfig::create(label: 'async');

        self::assertSame(['async'], $config->transports);
    }

    public function testCreateWithDefaults(): void
    {
        $config = ConsumerConfig::create(label: 'async');

        self::assertSame('async', $config->label);
        self::assertSame(['async'], $config->transports);
        self::assertSame(3, $config->failureLimit);
        self::assertSame(60, $config->failureWindowSeconds);
        self::assertSame(1, $config->backoffBaseSeconds);
        self::assertSame(30, $config->backoffMaxSeconds);
        self::assertSame(200, $config->pollIntervalMs);
        self::assertSame([], $config->consumeArgs->toCliArguments());
        self::assertSame(1, $config->autoscaler->min);
        self::assertSame(1, $config->autoscaler->max);
    }

    public function testCreateWithNullConsumeArgsUsesEmptyDefault(): void
    {
        $config = ConsumerConfig::create(label: 'failed');

        self::assertNull($config->consumeArgs->memoryLimit);
        self::assertNull($config->consumeArgs->timeLimit);
        self::assertNull($config->consumeArgs->limit);
        self::assertNull($config->consumeArgs->sleep);
        self::assertSame([], $config->consumeArgs->queues);
        self::assertSame([], $config->consumeArgs->extra);
    }

    public function testConsumeArgsIsAccessible(): void
    {
        $consumeArgs = ConsumeArgs::create(
            memoryLimit: 128,
            timeLimit: 60,
            queues: ['high'],
        );

        $config = ConsumerConfig::create(
            label: 'priority',
            consumeArgs: $consumeArgs,
        );

        self::assertSame(128, $config->consumeArgs->memoryLimit);
        self::assertSame(60, $config->consumeArgs->timeLimit);
        self::assertSame(['high'], $config->consumeArgs->queues);
    }

    public function testEmptyTransportListRejected(): void
    {
        $this->expectException(\AssertionError::class);

        new ConsumerConfig(
            label: 'bad',
            transports: [],
            failureLimit: 3,
            failureWindowSeconds: 60,
            backoffBaseSeconds: 1,
            backoffMaxSeconds: 30,
            pollIntervalMs: 200,
            consumeArgs: ConsumeArgs::create(),
            autoscaler: AutoscalerConfig::legacyFixed(1),
        );
    }
}
