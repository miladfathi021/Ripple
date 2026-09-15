<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskSeverity;

final class RiskFactorResultTest extends TestCase
{
    public function testEmptyResult(): void
    {
        $result = RiskFactorResult::empty();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->count());
        $this->assertSame([], $result->all());
    }

    public function testMultipleFactorsAreCounted(): void
    {
        $result = RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_DEEP_IMPACT, 'Deep dependency chain', 2),
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, 'High fan-in', 8, 'B'),
        ]);

        $this->assertFalse($result->isEmpty());
        $this->assertSame(2, $result->count());
        $this->assertCount(2, $result->all());
    }

    public function testOrderingIsDeterministicByCodeThenSymbol(): void
    {
        $result = RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, 'Multiple changed symbols', 2),
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, 'High fan-in', 5, 'Z'),
            $this->factor(RiskFactor::CODE_LARGE_BLAST_RADIUS, 'Large blast radius', 5),
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, 'High fan-in', 10, 'A'),
            $this->factor(RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES, 'Multiple dependency types', 2, 'M'),
            $this->factor(RiskFactor::CODE_DEEP_IMPACT, 'Deep dependency chain', 3),
        ]);

        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
            ],
            array_map(static fn (RiskFactor $factor): string => $factor->code, $result->all()),
        );
        $this->assertSame('A', $result->all()[0]->evidence['symbol']);
        $this->assertSame('Z', $result->all()[1]->evidence['symbol']);
    }

    public function testAllDoesNotExposeAMutableBackingCollection(): void
    {
        $result = RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_DEEP_IMPACT, 'Deep dependency chain', 2),
        ]);

        $copy = $result->all();
        $copy[] = $this->factor(RiskFactor::CODE_LARGE_BLAST_RADIUS, 'Large blast radius', 5);

        $this->assertSame(1, $result->count());
        $this->assertCount(1, $result->all());
    }

    private function factor(string $code, string $title, int $value, ?string $symbol = null): RiskFactor
    {
        $evidence = $symbol === null ? ['value' => $value] : ['symbol' => $symbol, 'value' => $value];

        return new RiskFactor(
            code: $code,
            severity: RiskSeverity::Warning,
            title: $title,
            description: $title,
            value: $value,
            evidence: $evidence,
        );
    }
}
