<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use InvalidArgumentException;

final class ReportFormatterFactory
{
    public function forFormat(string $format): ReportFormatter
    {
        return match ($format) {
            'text' => new TextReportFormatter(),
            'json' => new JsonReportFormatter(),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported format "%s". Use "text" or "json".', $format),
            ),
        };
    }
}
