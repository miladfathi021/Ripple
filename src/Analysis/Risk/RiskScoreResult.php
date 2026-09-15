<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

final readonly class RiskScoreResult
{
    /**
     * @param list<RiskScoreContribution> $contributions
     */
    public function __construct(
        private RiskScore $score,
        private RiskLevel $level,
        private array $contributions,
    ) {
    }

    public static function none(): self
    {
        $score = RiskScore::of(0);

        return new self($score, RiskLevel::fromScore($score->value()), []);
    }

    public function score(): RiskScore
    {
        return $this->score;
    }

    public function level(): RiskLevel
    {
        return $this->level;
    }

    /**
     * @return list<RiskScoreContribution>
     */
    public function contributions(): array
    {
        return $this->contributions;
    }

    public function hasContributions(): bool
    {
        return $this->contributions !== [];
    }
}
