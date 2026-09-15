<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

use Ripple\Analysis\Risk\Rules\DeepImpactRule;
use Ripple\Analysis\Risk\Rules\HighFanInRule;
use Ripple\Analysis\Risk\Rules\HistoricalChurnRule;
use Ripple\Analysis\Risk\Rules\LargeBlastRadiusRule;
use Ripple\Analysis\Risk\Rules\MultipleChangedSymbolsRule;
use Ripple\Analysis\Risk\Rules\MultipleDependencyTypesRule;

final class RiskFactorAnalyzer
{
    /**
     * @param list<RiskFactorRule> $rules
     */
    public function __construct(
        private readonly array $rules = [
            new HighFanInRule(),
            new LargeBlastRadiusRule(),
            new DeepImpactRule(),
            new MultipleDependencyTypesRule(),
            new MultipleChangedSymbolsRule(),
            new HistoricalChurnRule(),
        ],
    ) {
    }

    public function analyze(RiskFactorContext $context): RiskFactorResult
    {
        $factors = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($context) as $factor) {
                $factors[] = $factor;
            }
        }

        return RiskFactorResult::fromFactors($factors);
    }
}
