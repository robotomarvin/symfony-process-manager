<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\Filter\WorkerIdFilter;

#[CoversClass(WorkerIdFilter::class)]
final class WorkerIdFilterTest extends TestCase
{
    public function testMatchesReturnsTrueForSameId(): void
    {
        $filter = new WorkerIdFilter(1);

        self::assertTrue($filter->matches(1));
    }

    public function testMatchesReturnsFalseForDifferentId(): void
    {
        $filter = new WorkerIdFilter(1);

        self::assertFalse($filter->matches(2));
    }
}
