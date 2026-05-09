<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Transport\ConsumeArgs;

#[CoversClass(ConsumeArgs::class)]
final class ConsumeArgsTest extends TestCase
{
    public function testCreateWithAllParameters(): void
    {
        $args = ConsumeArgs::create(
            memoryLimit: 128,
            timeLimit: 60,
            limit: 10,
            sleep: 5,
            queues: ['high', 'low'],
            extra: ['--no-reset'],
        );

        self::assertSame(128, $args->memoryLimit);
        self::assertSame(60, $args->timeLimit);
        self::assertSame(10, $args->limit);
        self::assertSame(5, $args->sleep);
        self::assertSame(['high', 'low'], $args->queues);
        self::assertSame(['--no-reset'], $args->extra);
    }

    public function testCreateWithDefaults(): void
    {
        $args = ConsumeArgs::create();

        self::assertNull($args->memoryLimit);
        self::assertNull($args->timeLimit);
        self::assertNull($args->limit);
        self::assertNull($args->sleep);
        self::assertSame([], $args->queues);
        self::assertSame([], $args->extra);
    }

    public function testToCliArgumentsWithAllParams(): void
    {
        $args = ConsumeArgs::create(
            memoryLimit: 128,
            timeLimit: 60,
            limit: 10,
            sleep: 5,
            queues: ['high', 'low'],
            extra: ['--no-reset', '--verbose'],
        );

        self::assertSame([
            '--memory-limit', '128',
            '--time-limit', '60',
            '--limit', '10',
            '--sleep', '5',
            'high', 'low',
            '--no-reset', '--verbose',
        ], $args->toCliArguments());
    }

    public function testToCliArgumentsWithOnlyMemoryLimit(): void
    {
        $args = ConsumeArgs::create(memoryLimit: 256);

        self::assertSame(['--memory-limit', '256'], $args->toCliArguments());
    }

    public function testToCliArgumentsSkipsNullValues(): void
    {
        $args = ConsumeArgs::create(
            memoryLimit: 128,
            limit: 10,
        );

        self::assertSame([
            '--memory-limit', '128',
            '--limit', '10',
        ], $args->toCliArguments());
    }

    public function testToCliArgumentsWithQueuesOnly(): void
    {
        $args = ConsumeArgs::create(queues: ['high', 'default']);

        self::assertSame(['high', 'default'], $args->toCliArguments());
    }

    public function testToCliArgumentsWithExtraOnly(): void
    {
        $args = ConsumeArgs::create(extra: ['--no-reset']);

        self::assertSame(['--no-reset'], $args->toCliArguments());
    }

    public function testToCliArgumentsReturnsEmptyArrayWhenAllDefaults(): void
    {
        $args = ConsumeArgs::create();

        self::assertSame([], $args->toCliArguments());
    }

    public function testToCliArgumentsOrderIsFlagsQueuesExtra(): void
    {
        $args = ConsumeArgs::create(
            timeLimit: 30,
            queues: ['priority'],
            extra: ['--custom'],
        );

        self::assertSame([
            '--time-limit', '30',
            'priority',
            '--custom',
        ], $args->toCliArguments());
    }

    public function testToCliArgumentsEmitsZeroMemoryAndTimeLimits(): void
    {
        $args = ConsumeArgs::create(
            memoryLimit: 0,
            timeLimit: 0,
            limit: 0,
            sleep: 0,
        );

        self::assertSame([
            '--memory-limit', '0',
            '--time-limit', '0',
            '--limit', '0',
            '--sleep', '0',
        ], $args->toCliArguments());
    }
}
