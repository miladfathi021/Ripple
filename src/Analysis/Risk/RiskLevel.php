<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score < 30 => self::Low,
            $score < 60 => self::Medium,
            default => self::High,
        };
    }
}
