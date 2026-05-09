<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Autoscaler\Smoother;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Autoscaler\Smoother\Ewma;

#[CoversClass(Ewma::class)]
final class EwmaTest extends TestCase
{
    public function testFirstSampleSeedsValue(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 30.0);
        $ewma->update(1.0, 5.0);

        self::assertSame(5.0, $ewma->value());
    }

    public function testZeroDeltaHoldsPriorValue(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 30.0);
        $ewma->update(1.0, 1.0);
        $ewma->update(0.0, 10.0);

        self::assertSame(1.0, $ewma->value());
    }

    public function testValueDecaysExponentiallyOverTime(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 30.0);
        $ewma->update(0.0, 0.0);
        $ewma->update(30.0, 1.0);

        // alpha = 1 - exp(-1) ≈ 0.6321
        self::assertEqualsWithDelta(0.6321, $ewma->value(), 0.001);
    }

    public function testRepeatedConstantInputsConvergeToValue(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 5.0);
        for ($i = 0; $i < 100; $i++) {
            $ewma->update(1.0, 7.0);
        }

        self::assertEqualsWithDelta(7.0, $ewma->value(), 0.001);
    }

    public function testHasSampleStartsFalseAndBecomesTrue(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 5.0);
        self::assertFalse($ewma->hasSample());

        $ewma->update(1.0, 1.0);
        self::assertTrue($ewma->hasSample());
    }

    public function testResetClearsValue(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 5.0);
        $ewma->update(1.0, 1.0);
        $ewma->reset();

        self::assertFalse($ewma->hasSample());
        self::assertSame(0.0, $ewma->value());
    }

    public function testNegativeDeltaSecondsIsNoOp(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 30.0);
        $ewma->update(0.0, 0.0);
        $ewma->update(10.0, 1.0); // seed real value
        $before = $ewma->value();

        $ewma->update(-5.0, 999.0);

        self::assertSame($before, $ewma->value());
    }

    public function testTinyTimeConstantConvergesNearInstantly(): void
    {
        // alpha = 1 - exp(-deltaSeconds / timeConstant). With τ ≪ Δt, alpha → 1
        // and the new sample dominates.
        $ewma = new Ewma(timeConstantSeconds: 0.001);
        $ewma->update(0.0, 0.0);
        $ewma->update(1.0, 100.0);

        self::assertEqualsWithDelta(100.0, $ewma->value(), 0.0001);
    }

    public function testValueIsZeroBeforeFirstSample(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 5.0);

        self::assertSame(0.0, $ewma->value());
        self::assertFalse($ewma->hasSample());
    }

    public function testIrregularIntervalsRespectTimeWeighting(): void
    {
        $ewma = new Ewma(timeConstantSeconds: 10.0);
        $ewma->update(0.0, 0.0);

        // A short tick gives small weight to the new sample.
        $ewma->update(0.1, 100.0);
        $shortValue = $ewma->value();

        $ewma->reset();
        $ewma->update(0.0, 0.0);

        // A long tick gives large weight to the same sample.
        $ewma->update(20.0, 100.0);
        $longValue = $ewma->value();

        self::assertGreaterThan($shortValue, $longValue);
    }
}
