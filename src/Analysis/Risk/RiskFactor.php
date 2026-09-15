<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

final readonly class RiskFactor
{
    public const CODE_HIGH_FAN_IN = 'high_fan_in';
    public const CODE_LARGE_BLAST_RADIUS = 'large_blast_radius';
    public const CODE_DEEP_IMPACT = 'deep_impact';
    public const CODE_MULTIPLE_DEPENDENCY_TYPES = 'multiple_dependency_types';
    public const CODE_MULTIPLE_CHANGED_SYMBOLS = 'multiple_changed_symbols';
    public const CODE_HISTORICAL_CHURN = 'historical_churn';

    /**
     * @param array<string, mixed> $evidence
     */
    public function __construct(
        public string $code,
        public RiskSeverity $severity,
        public string $title,
        public string $description,
        public int $value,
        public array $evidence,
    ) {
    }
}
