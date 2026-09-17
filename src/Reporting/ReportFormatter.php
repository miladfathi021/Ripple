<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;

interface ReportFormatter
{
    public function format(
        AnalysisResult $result,
        ?AIPrExplanation $explanation = null,
        ?AIRiskExplanation $riskExplanation = null,
    ): string;
}
