<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

interface RiskFactorRule
{
    /**
     * @return list<RiskFactor>
     */
    public function evaluate(RiskFactorContext $context): array;
}
