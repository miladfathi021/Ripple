<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk\Rules;

use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskFactorRule;
use Ripple\Analysis\Risk\RiskSeverity;

final class LargeBlastRadiusRule implements RiskFactorRule
{
    public function evaluate(RiskFactorContext $context): array
    {
        $ids = [];
        foreach ($context->blastRadius->entries as $entry) {
            $ids[$entry->impactedSymbolId] = true;
        }
        $count = count($ids);

        if ($count < 5) {
            return [];
        }

        $severity = $count >= 10 ? RiskSeverity::High : RiskSeverity::Warning;

        return [
            new RiskFactor(
                code: RiskFactor::CODE_LARGE_BLAST_RADIUS,
                severity: $severity,
                title: 'Large blast radius',
                description: $count . ' symbols may be affected by this change.',
                value: $count,
                evidence: [
                    'affected_symbols' => $count,
                ],
            ),
        ];
    }
}
