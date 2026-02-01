<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\WorkerContext;
use SymfonyProcessManager\Ipc\WorkerMetadata;

#[CoversClass(WorkerContext::class)]
final class WorkerContextTest extends TestCase
{
    public function testCurrentReturnsNullByDefault(): void
    {
        $context = new WorkerContext();

        self::assertNull($context->current());
    }

    public function testSetCurrentMakesMetadataAvailable(): void
    {
        $context = new WorkerContext();
        $metadata = new WorkerMetadata(1, 'async');

        $context->setCurrent($metadata);

        self::assertSame($metadata, $context->current());
    }

    public function testClearRemovesMetadata(): void
    {
        $context = new WorkerContext();
        $context->setCurrent(new WorkerMetadata(1, 'async'));

        $context->clear();

        self::assertNull($context->current());
    }

    public function testSetCurrentOverridesPrevious(): void
    {
        $context = new WorkerContext();
        $context->setCurrent(new WorkerMetadata(1, 'async'));

        $second = new WorkerMetadata(2, 'sync');
        $context->setCurrent($second);

        self::assertSame($second, $context->current());
    }
}
