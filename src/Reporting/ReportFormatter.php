<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;

interface ReportFormatter
{
    public function format(AnalysisResult $result): string;
}
