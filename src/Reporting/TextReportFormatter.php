<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;

final class TextReportFormatter implements ReportFormatter
{
    public function format(AnalysisResult $result): string
    {
        return "🌊 Ripple\n\n{$result->message}";
    }
}
