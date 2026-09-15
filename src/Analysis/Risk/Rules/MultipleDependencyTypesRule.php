<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk\Rules;

use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskFactorRule;
use Ripple\Analysis\Risk\RiskSeverity;

final class MultipleDependencyTypesRule implements RiskFactorRule
{
    public function evaluate(RiskFactorContext $context): array
    {
        /** @var list<array{symbol: string, dependency_types: list<string>, severity: RiskSeverity}> $qualified */
        $qualified = [];
        foreach ($context->changedSymbolIds() as $symbolId) {
            $types = $this->incomingTypes($context, $symbolId);
            $count = count($types);
            if ($count < 2) {
                continue;
            }

            $qualified[] = [
                'symbol' => $symbolId,
                'dependency_types' => $types,
                'severity' => $count >= 3 ? RiskSeverity::Warning : RiskSeverity::Info,
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
                    count($right['dependency_types']),
                    $left['symbol'],
                ] <=> [
                    self::severityRank($left['severity']),
                    count($left['dependency_types']),
                    $right['symbol'],
                ];
            },
        );

        $primary = $qualified[0];
        $count = count($primary['dependency_types']);
        $evidence = [
            'symbol' => $primary['symbol'],
            'dependency_types' => $primary['dependency_types'],
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
                    'dependency_types' => $row['dependency_types'],
                ],
                $all,
            );
        }

        return [
            new RiskFactor(
                code: RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                severity: $primary['severity'],
                title: 'Multiple dependency types',
                description: $primary['symbol'] . ' has dependents through ' . $count . ' different dependency types.',
                value: $count,
                evidence: $evidence,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function incomingTypes(RiskFactorContext $context, string $symbolId): array
    {
        $types = [];
        foreach ($context->reverseGraph->getDependentEdges($symbolId) as $edge) {
            if ($edge->source === $symbolId) {
                continue;
            }
            $types[$edge->type->value] = true;
        }

        $values = array_keys($types);
        sort($values, SORT_STRING);

        return $values;
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
