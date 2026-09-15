<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk\Rules;

use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskFactorRule;
use Ripple\Analysis\Risk\RiskSeverity;

final class DeepImpactRule implements RiskFactorRule
{
    public function evaluate(RiskFactorContext $context): array
    {
        $maxDepth = 0;
        foreach ($context->blastRadius->entries as $entry) {
            $maxDepth = max($maxDepth, $entry->depth);
        }

        if ($maxDepth < 2) {
            return [];
        }

        $severity = $maxDepth >= 4 ? RiskSeverity::High : RiskSeverity::Warning;

        return [
            new RiskFactor(
                code: RiskFactor::CODE_DEEP_IMPACT,
                severity: $severity,
                title: 'Deep dependency chain',
                description: 'The change can reach impacted symbols up to ' . $maxDepth . ' dependency levels away.',
                value: $maxDepth,
                evidence: [
                    'max_depth' => $maxDepth,
                ],
            ),
        ];
    }
}
