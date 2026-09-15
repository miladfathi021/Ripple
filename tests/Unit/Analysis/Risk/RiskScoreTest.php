<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use Error;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\InvalidRiskScoreException;
use Ripple\Analysis\Risk\RiskScore;

final class RiskScoreTest extends TestCase
{
    public function testAcceptsZero(): void
    {
        $this->assertSame(0, RiskScore::of(0)->value());
    }

    public function testAcceptsOneHundred(): void
    {
        $this->assertSame(100, RiskScore::of(100)->value());
    }

    public function testAcceptsValuesBetweenBounds(): void
    {
        $this->assertSame(78, RiskScore::of(78)->value());
    }

    public function testRejectsNegativeValues(): void
    {
        $this->expectException(InvalidRiskScoreException::class);
        $this->expectExceptionMessage('Risk score must be between 0 and 100, got -1.');
        RiskScore::of(-1);
    }

    public function testRejectsValuesAboveOneHundred(): void
    {
        $this->expectException(InvalidRiskScoreException::class);
        $this->expectExceptionMessage('Risk score must be between 0 and 100, got 101.');
        RiskScore::of(101);
    }

    public function testIsImmutable(): void
    {
        $score = RiskScore::of(40);

        $this->expectException(Error::class);
        $score->value = 99;
    }
}
