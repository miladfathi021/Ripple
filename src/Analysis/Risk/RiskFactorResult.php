<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

final readonly class RiskFactorResult
{
    /**
     * @param list<RiskFactor> $factors
     */
    public function __construct(
        private array $factors,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<RiskFactor> $factors
     */
    public static function fromFactors(array $factors): self
    {
        usort(
            $factors,
            static function (RiskFactor $left, RiskFactor $right): int {
                return [
                    self::codeRank($left->code),
                    self::symbolKey($left),
                    $left->description,
                    $left->value,
                ] <=> [
                    self::codeRank($right->code),
                    self::symbolKey($right),
                    $right->description,
                    $right->value,
                ];
            },
        );

        return new self($factors);
    }

    /**
     * @return list<RiskFactor>
     */
    public function all(): array
    {
        return $this->factors;
    }

    public function count(): int
    {
        return count($this->factors);
    }

    public function isEmpty(): bool
    {
        return $this->factors === [];
    }

    private static function codeRank(string $code): int
    {
        return match ($code) {
            RiskFactor::CODE_HIGH_FAN_IN => 1,
            RiskFactor::CODE_LARGE_BLAST_RADIUS => 2,
            RiskFactor::CODE_DEEP_IMPACT => 3,
            RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES => 4,
            RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS => 5,
            RiskFactor::CODE_HISTORICAL_CHURN => 6,
            default => 100,
        };
    }

    private static function symbolKey(RiskFactor $factor): string
    {
        $symbol = $factor->evidence['symbol'] ?? '';

        return is_string($symbol) ? $symbol : '';
    }
}
