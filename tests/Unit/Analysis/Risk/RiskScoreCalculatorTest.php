<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\DuplicateRiskFactorCodeException;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskLevel;
use Ripple\Analysis\Risk\RiskScoreCalculator;
use Ripple\Analysis\Risk\RiskScoreContribution;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\UnknownRiskFactorCodeException;

final class RiskScoreCalculatorTest extends TestCase
{
    public function testEmptyFactorsScoreZeroLowWithNoContributions(): void
    {
        $result = (new RiskScoreCalculator())->calculate(RiskFactorResult::empty());

        $this->assertSame(0, $result->score()->value());
        $this->assertSame(RiskLevel::Low, $result->level());
        $this->assertFalse($result->hasContributions());
        $this->assertSame([], $result->contributions());
    }

    #[DataProvider('singleFactorContributions')]
    public function testSingleFactorContribution(
        string $code,
        string $title,
        RiskSeverity $severity,
        int $expectedWeight,
        int $expectedContribution,
    ): void {
        $result = (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor($code, $severity, $title),
        ]));

        $this->assertSame($expectedContribution, $result->score()->value());
        $this->assertCount(1, $result->contributions());
        $this->assertSame($code, $result->contributions()[0]->code);
        $this->assertSame($title, $result->contributions()[0]->title);
        $this->assertSame($severity, $result->contributions()[0]->severity);
        $this->assertSame($expectedWeight, $result->contributions()[0]->maxWeight);
        $this->assertSame($expectedContribution, $result->contributions()[0]->contribution);
        $this->assertSame(RiskLevel::fromScore($expectedContribution), $result->level());
    }

    /**
     * @return array<string, array{string, string, RiskSeverity, int, int}>
     */
    public static function singleFactorContributions(): array
    {
        return [
            'high_fan_in info' => [RiskFactor::CODE_HIGH_FAN_IN, 'High fan-in', RiskSeverity::Info, 20, 10],
            'high_fan_in warning' => [RiskFactor::CODE_HIGH_FAN_IN, 'High fan-in', RiskSeverity::Warning, 20, 15],
            'high_fan_in high' => [RiskFactor::CODE_HIGH_FAN_IN, 'High fan-in', RiskSeverity::High, 20, 20],
            'large_blast_radius info' => [RiskFactor::CODE_LARGE_BLAST_RADIUS, 'Large blast radius', RiskSeverity::Info, 20, 10],
            'large_blast_radius warning' => [RiskFactor::CODE_LARGE_BLAST_RADIUS, 'Large blast radius', RiskSeverity::Warning, 20, 15],
            'large_blast_radius high' => [RiskFactor::CODE_LARGE_BLAST_RADIUS, 'Large blast radius', RiskSeverity::High, 20, 20],
            'deep_impact info' => [RiskFactor::CODE_DEEP_IMPACT, 'Deep dependency chain', RiskSeverity::Info, 15, 8],
            'deep_impact warning' => [RiskFactor::CODE_DEEP_IMPACT, 'Deep dependency chain', RiskSeverity::Warning, 15, 11],
            'deep_impact high' => [RiskFactor::CODE_DEEP_IMPACT, 'Deep dependency chain', RiskSeverity::High, 15, 15],
            'multiple_dependency_types info' => [RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES, 'Multiple dependency types', RiskSeverity::Info, 10, 5],
            'multiple_dependency_types warning' => [RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES, 'Multiple dependency types', RiskSeverity::Warning, 10, 8],
            'multiple_dependency_types high' => [RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES, 'Multiple dependency types', RiskSeverity::High, 10, 10],
            'multiple_changed_symbols info' => [RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, 'Multiple changed symbols', RiskSeverity::Info, 10, 5],
            'multiple_changed_symbols warning' => [RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, 'Multiple changed symbols', RiskSeverity::Warning, 10, 8],
            'multiple_changed_symbols high' => [RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, 'Multiple changed symbols', RiskSeverity::High, 10, 10],
            'historical_churn info' => [RiskFactor::CODE_HISTORICAL_CHURN, 'Historical churn', RiskSeverity::Info, 25, 13],
            'historical_churn warning' => [RiskFactor::CODE_HISTORICAL_CHURN, 'Historical churn', RiskSeverity::Warning, 25, 19],
            'historical_churn high' => [RiskFactor::CODE_HISTORICAL_CHURN, 'Historical churn', RiskSeverity::High, 25, 25],
        ];
    }

    public function testCombinedFactorsProduceExplainableHighScore(): void
    {
        $result = (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::Warning, 'High fan-in'),
            $this->factor(RiskFactor::CODE_LARGE_BLAST_RADIUS, RiskSeverity::High, 'Large blast radius'),
            $this->factor(RiskFactor::CODE_DEEP_IMPACT, RiskSeverity::Warning, 'Deep dependency chain'),
            $this->factor(RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES, RiskSeverity::Info, 'Multiple dependency types'),
            $this->factor(RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, RiskSeverity::Warning, 'Multiple changed symbols'),
        ]));

        $this->assertSame(59, $result->score()->value());
        $this->assertSame(RiskLevel::Medium, $result->level());
        $this->assertSame(
            [15, 20, 11, 5, 8],
            array_map(static fn (RiskScoreContribution $row): int => $row->contribution, $result->contributions()),
        );
    }

    public function testHistoricalChurnWithExistingFactorsScoresFiftyFiveMedium(): void
    {
        $result = (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::Warning, 'High fan-in'),
            $this->factor(RiskFactor::CODE_LARGE_BLAST_RADIUS, RiskSeverity::Warning, 'Large blast radius'),
            $this->factor(RiskFactor::CODE_HISTORICAL_CHURN, RiskSeverity::High, 'Historical churn'),
        ]));

        $this->assertSame(55, $result->score()->value());
        $this->assertSame(RiskLevel::Medium, $result->level());
        $this->assertSame(
            [15, 15, 25],
            array_map(static fn (RiskScoreContribution $row): int => $row->contribution, $result->contributions()),
        );
        $this->assertLessThanOrEqual(100, $result->score()->value());
    }

    public function testDefaultWeightsSumToOneHundred(): void
    {
        $this->assertSame(100, array_sum(RiskScoreCalculator::WEIGHTS));
        $this->assertArrayHasKey(RiskFactor::CODE_HISTORICAL_CHURN, RiskScoreCalculator::WEIGHTS);
        $this->assertSame(25, RiskScoreCalculator::WEIGHTS[RiskFactor::CODE_HISTORICAL_CHURN]);
    }

    public function testContributionOrderFollowsRiskFactorOrderNotContributionAmount(): void
    {
        $result = (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, RiskSeverity::High, 'Multiple changed symbols'),
            $this->factor(RiskFactor::CODE_DEEP_IMPACT, RiskSeverity::High, 'Deep dependency chain'),
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::Info, 'High fan-in'),
        ]));

        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
            ],
            array_map(static fn (RiskScoreContribution $row): string => $row->code, $result->contributions()),
        );
        $this->assertSame([10, 15, 10], array_map(
            static fn (RiskScoreContribution $row): int => $row->contribution,
            $result->contributions(),
        ));
    }

    public function testBoundaryTwentyNineIsLow(): void
    {
        $calculator = new RiskScoreCalculator([
            RiskFactor::CODE_HIGH_FAN_IN => 29,
        ]);
        $result = $calculator->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::High, 'High fan-in'),
        ]));

        $this->assertSame(29, $result->score()->value());
        $this->assertSame(RiskLevel::Low, $result->level());
    }

    public function testBoundaryThirtyIsMedium(): void
    {
        $calculator = new RiskScoreCalculator([
            RiskFactor::CODE_HIGH_FAN_IN => 30,
        ]);
        $result = $calculator->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::High, 'High fan-in'),
        ]));

        $this->assertSame(30, $result->score()->value());
        $this->assertSame(RiskLevel::Medium, $result->level());
    }

    public function testBoundaryFiftyNineIsMedium(): void
    {
        $calculator = new RiskScoreCalculator([
            RiskFactor::CODE_HIGH_FAN_IN => 59,
        ]);
        $result = $calculator->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::High, 'High fan-in'),
        ]));

        $this->assertSame(59, $result->score()->value());
        $this->assertSame(RiskLevel::Medium, $result->level());
    }

    public function testBoundarySixtyIsHigh(): void
    {
        $calculator = new RiskScoreCalculator([
            RiskFactor::CODE_HIGH_FAN_IN => 60,
        ]);
        $result = $calculator->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::High, 'High fan-in'),
        ]));

        $this->assertSame(60, $result->score()->value());
        $this->assertSame(RiskLevel::High, $result->level());
    }

    public function testScoreIsCappedAtOneHundred(): void
    {
        $calculator = new RiskScoreCalculator(array_merge(RiskScoreCalculator::WEIGHTS, [
            'future_extra_factor' => 90,
        ]));
        $result = $calculator->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::High, 'High fan-in'),
            $this->factor('future_extra_factor', RiskSeverity::High, 'Future extra factor'),
        ]));

        $this->assertSame(100, $result->score()->value());
        $this->assertSame(RiskLevel::High, $result->level());
        $this->assertSame(20, $result->contributions()[0]->contribution);
        $this->assertSame(90, $result->contributions()[1]->contribution);
    }

    public function testDuplicateFactorCodesAreRejected(): void
    {
        $this->expectException(DuplicateRiskFactorCodeException::class);
        $this->expectExceptionMessage('Duplicate risk factor code: high_fan_in');

        (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::Warning, 'High fan-in', 'A'),
            $this->factor(RiskFactor::CODE_HIGH_FAN_IN, RiskSeverity::High, 'High fan-in', 'B'),
        ]));
    }

    public function testUnknownFactorCodeIsRejected(): void
    {
        $this->expectException(UnknownRiskFactorCodeException::class);
        $this->expectExceptionMessage('Unknown risk factor code: guessed_public_api');

        (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor('guessed_public_api', RiskSeverity::High, 'Public API'),
        ]));
    }

    public function testResultIsImmutable(): void
    {
        $result = (new RiskScoreCalculator())->calculate(RiskFactorResult::fromFactors([
            $this->factor(RiskFactor::CODE_DEEP_IMPACT, RiskSeverity::Warning, 'Deep dependency chain'),
        ]));

        $copy = $result->contributions();
        $copy[] = new RiskScoreContribution(
            RiskFactor::CODE_HIGH_FAN_IN,
            'High fan-in',
            RiskSeverity::High,
            25,
            25,
        );

        $this->assertCount(1, $result->contributions());
        $this->assertTrue($result->hasContributions());
    }

    private function factor(
        string $code,
        RiskSeverity $severity,
        string $title,
        ?string $symbol = null,
    ): RiskFactor {
        return new RiskFactor(
            code: $code,
            severity: $severity,
            title: $title,
            description: $title,
            value: 1,
            evidence: $symbol === null ? [] : ['symbol' => $symbol],
        );
    }
}
