<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk\Rules;

use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskFactorRule;
use Ripple\Analysis\Risk\RiskSeverity;

final class HighFanInRule implements RiskFactorRule
{
    public function evaluate(RiskFactorContext $context): array
    {
        /** @var list<array{symbol: string, direct_dependents: int, severity: RiskSeverity}> $qualified */
        $qualified = [];
        foreach ($context->changedSymbolIds() as $symbolId) {
            $count = $this->directDependentCount($context, $symbolId);
            if ($count < 5) {
                continue;
            }

            $qualified[] = [
                'symbol' => $symbolId,
                'direct_dependents' => $count,
                'severity' => $count >= 10 ? RiskSeverity::High : RiskSeverity::Warning,
            ];
        }

        if ($qualified === []) {
            return [];
        }

        usort(
            $qualified,
            static function (array $left, array $right): int {
                return [
                    self::severityRank($right['severity']),
                    $right['direct_dependents'],
                    $left['symbol'],
                ] <=> [
                    self::severityRank($left['severity']),
                    $left['direct_dependents'],
                    $right['symbol'],
                ];
            },
        );

        $primary = $qualified[0];
        $evidence = [
            'symbol' => $primary['symbol'],
            'direct_dependents' => $primary['direct_dependents'],
        ];
        if (count($qualified) > 1) {
            $all = $qualified;
            usort(
                $all,
                static fn (array $left, array $right): int => $left['symbol'] <=> $right['symbol'],
            );
            $evidence['symbols'] = array_map(
                static fn (array $row): array => [
                    'symbol' => $row['symbol'],
                    'direct_dependents' => $row['direct_dependents'],
                ],
                $all,
            );
        }

        return [
            new RiskFactor(
                code: RiskFactor::CODE_HIGH_FAN_IN,
                severity: $primary['severity'],
                title: 'High fan-in',
                description: $primary['symbol'] . ' has ' . $primary['direct_dependents'] . ' direct dependents.',
                value: $primary['direct_dependents'],
                evidence: $evidence,
            ),
        ];
    }

    private function directDependentCount(RiskFactorContext $context, string $symbolId): int
    {
        $dependents = [];
        foreach ($context->reverseGraph->getDependents($symbolId) as $dependent) {
            if ($dependent === $symbolId) {
                continue;
            }
            $dependents[$dependent] = true;
        }

        return count($dependents);
    }

    private static function severityRank(RiskSeverity $severity): int
    {
        return match ($severity) {
            RiskSeverity::High => 3,
            RiskSeverity::Warning => 2,
            RiskSeverity::Info => 1,
        };
    }
}
