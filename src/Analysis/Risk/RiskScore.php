<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

final readonly class RiskScore
{
    private function __construct(
        private int $value,
    ) {
    }

    public static function of(int $value): self
    {
        if ($value < 0 || $value > 100) {
            throw new InvalidRiskScoreException(
                "Risk score must be between 0 and 100, got {$value}.",
            );
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }
}
