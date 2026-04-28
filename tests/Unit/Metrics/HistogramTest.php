<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Metrics\Histogram;

#[CoversClass(Histogram::class)]
final class HistogramTest extends TestCase
{
    public function testGetNameAndHelp(): void
    {
        $histogram = new Histogram('duration', 'Duration in seconds', [0.1, 1.0]);

        self::assertSame('duration', $histogram->getName());
        self::assertSame('Duration in seconds', $histogram->getHelp());
    }

    public function testBucketsAreSortedAndDeduped(): void
    {
        $histogram = new Histogram('h', 'h', [1.0, 0.1, 0.5, 0.1, 5.0]);

        self::assertSame([0.1, 0.5, 1.0, 5.0], $histogram->getBuckets());
    }

    public function testObserveAccumulatesCumulativeBucketCounts(): void
    {
        $histogram = new Histogram('h', 'h', [0.1, 0.5, 1.0]);

        $histogram->observe(0.05);
        $histogram->observe(0.3);
        $histogram->observe(0.8);
        $histogram->observe(2.0);

        $values = $histogram->getValues();
        self::assertCount(1, $values);

        $entry = $values[0];
        self::assertSame([], $entry['labels']);
        self::assertSame([1, 2, 3], $entry['bucketCounts']);
        self::assertSame(4, $entry['count']);
        self::assertSame(0.05 + 0.3 + 0.8 + 2.0, $entry['sum']);
    }

    public function testValueOnBoundaryFallsInsideBucket(): void
    {
        $histogram = new Histogram('h', 'h', [0.1, 1.0]);

        $histogram->observe(0.1);
        $histogram->observe(1.0);

        $entry = $histogram->getValues()[0];

        self::assertSame([1, 2], $entry['bucketCounts']);
        self::assertSame(2, $entry['count']);
    }

    public function testDifferentLabelSetsAreTrackedIndependently(): void
    {
        $histogram = new Histogram('h', 'h', [0.1, 1.0]);

        $histogram->observe(0.05, ['transport' => 'a']);
        $histogram->observe(0.5, ['transport' => 'a']);
        $histogram->observe(2.0, ['transport' => 'b']);

        $values = $histogram->getValues();
        self::assertCount(2, $values);

        $byTransport = [];
        foreach ($values as $entry) {
            $byTransport[$entry['labels']['transport']] = $entry;
        }

        self::assertSame([1, 2], $byTransport['a']['bucketCounts']);
        self::assertSame(2, $byTransport['a']['count']);
        self::assertSame([0, 0], $byTransport['b']['bucketCounts']);
        self::assertSame(1, $byTransport['b']['count']);
    }

    public function testIntegerBucketsAreNormalizedToFloat(): void
    {
        $histogram = new Histogram('h', 'h', [1, 5, 10]);

        self::assertSame([1.0, 5.0, 10.0], $histogram->getBuckets());
    }
}
