<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;

final class JsonReportFormatter implements ReportFormatter
{
    public function format(AnalysisResult $result): string
    {
        return json_encode(
            [
                'status' => $result->status,
                'message' => $result->message,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
