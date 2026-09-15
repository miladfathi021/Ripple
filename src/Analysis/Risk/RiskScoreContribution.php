<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

final readonly class RiskScoreContribution
{
    public function __construct(
        public string $code,
        public string $title,
        public RiskSeverity $severity,
        public int $maxWeight,
        public int $contribution,
    ) {
    }
}
