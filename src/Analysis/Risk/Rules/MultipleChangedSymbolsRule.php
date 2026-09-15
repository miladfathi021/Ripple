<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk\Rules;

use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskFactorRule;
use Ripple\Analysis\Risk\RiskSeverity;

final class MultipleChangedSymbolsRule implements RiskFactorRule
{
    public function evaluate(RiskFactorContext $context): array
    {
        $count = count($context->changedSymbolIds());
        if ($count < 2) {
            return [];
        }

        $severity = $count >= 4 ? RiskSeverity::Warning : RiskSeverity::Info;

        return [
            new RiskFactor(
                code: RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
                severity: $severity,
                title: 'Multiple changed symbols',
                description: $count . ' symbols were changed in this analysis.',
                value: $count,
                evidence: [
                    'changed_symbols' => $count,
                ],
            ),
        ];
    }
}
