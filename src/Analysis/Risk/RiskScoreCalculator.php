<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

final class RiskScoreCalculator
{
    /**
     * @var array<string, int>
     */
    public const WEIGHTS = [
        RiskFactor::CODE_HIGH_FAN_IN => 20,
        RiskFactor::CODE_LARGE_BLAST_RADIUS => 20,
        RiskFactor::CODE_DEEP_IMPACT => 15,
        RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES => 10,
        RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS => 10,
        RiskFactor::CODE_HISTORICAL_CHURN => 25,
    ];

    /**
     * @param array<string, int> $weights
     */
    public function __construct(
        private readonly array $weights = self::WEIGHTS,
    ) {
    }

    public function calculate(RiskFactorResult $factors): RiskScoreResult
    {
        $seen = [];
        $contributions = [];
        $total = 0;

        foreach ($factors->all() as $factor) {
            if (isset($seen[$factor->code])) {
                throw new DuplicateRiskFactorCodeException($factor->code);
            }
            $seen[$factor->code] = true;

            $maxWeight = $this->weights[$factor->code] ?? null;
            if ($maxWeight === null) {
                throw new UnknownRiskFactorCodeException($factor->code);
            }

            $contribution = $this->contributionForSeverity($maxWeight, $factor->severity);
            $contributions[] = new RiskScoreContribution(
                code: $factor->code,
                title: $factor->title,
                severity: $factor->severity,
                maxWeight: $maxWeight,
                contribution: $contribution,
            );
            $total += $contribution;
        }

        $score = RiskScore::of(min(100, $total));

        return new RiskScoreResult($score, RiskLevel::fromScore($score->value()), $contributions);
    }

    private function contributionForSeverity(int $maxWeight, RiskSeverity $severity): int
    {
        return match ($severity) {
            RiskSeverity::Info => intdiv($maxWeight * 50 + 50, 100),
            RiskSeverity::Warning => intdiv($maxWeight * 75 + 50, 100),
            RiskSeverity::High => $maxWeight,
        };
    }
}
