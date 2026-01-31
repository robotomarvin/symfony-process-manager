<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Transport\ConsumeArgs;
use SymfonyProcessManager\Transport\TransportConfig;

#[CoversClass(TransportConfig::class)]
final class TransportConfigTest extends TestCase
{
    public function testCreateWithAllParameters(): void
    {
        $consumeArgs = ConsumeArgs::create(memoryLimit: 256);

        $config = TransportConfig::create(
            transport: 'async',
            processes: 4,
            failureLimit: 5,
            failureWindowSeconds: 120,
            backoffBaseSeconds: 2,
            backoffMaxSeconds: 60,
            pollIntervalMs: 500,
            consumeArgs: $consumeArgs,
        );

        self::assertSame('async', $config->transport);
        self::assertSame(4, $config->processes);
        self::assertSame(5, $config->failureLimit);
        self::assertSame(120, $config->failureWindowSeconds);
        self::assertSame(2, $config->backoffBaseSeconds);
        self::assertSame(60, $config->backoffMaxSeconds);
        self::assertSame(500, $config->pollIntervalMs);
        self::assertSame($consumeArgs, $config->consumeArgs);
    }

    public function testCreateWithDefaults(): void
    {
        $config = TransportConfig::create(transport: 'async');

        self::assertSame('async', $config->transport);
        self::assertSame(1, $config->processes);
        self::assertSame(3, $config->failureLimit);
        self::assertSame(60, $config->failureWindowSeconds);
        self::assertSame(1, $config->backoffBaseSeconds);
        self::assertSame(30, $config->backoffMaxSeconds);
        self::assertSame(200, $config->pollIntervalMs);
        self::assertSame([], $config->consumeArgs->toCliArguments());
    }

    public function testCreateWithNullConsumeArgsUsesEmptyDefault(): void
    {
        $config = TransportConfig::create(transport: 'failed');

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

        $config = TransportConfig::create(
            transport: 'priority',
            consumeArgs: $consumeArgs,
        );

        self::assertSame(128, $config->consumeArgs->memoryLimit);
        self::assertSame(60, $config->consumeArgs->timeLimit);
        self::assertSame(['high'], $config->consumeArgs->queues);
    }

    public function testTransportNameIsRequired(): void
    {
        $config = TransportConfig::create(transport: 'notifications');

        self::assertSame('notifications', $config->transport);
    }
}
