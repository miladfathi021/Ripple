<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

enum RiskSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case High = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::High => 3,
        };
    }
}
