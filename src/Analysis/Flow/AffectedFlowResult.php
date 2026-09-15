<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

final readonly class AffectedFlowResult
{
    /**
     * @param list<AffectedFlow> $flows
     */
    public function __construct(
        private array $flows,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<AffectedFlow> $flows
     */
    public static function fromFlows(array $flows): self
    {
        usort(
            $flows,
            static function (AffectedFlow $left, AffectedFlow $right): int {
                $leftTerminal = $left->nodes === [] ? '' : $left->nodes[array_key_last($left->nodes)]->id;
                $rightTerminal = $right->nodes === [] ? '' : $right->nodes[array_key_last($right->nodes)]->id;

                return [
                    $left->changedSymbolId,
                    $left->depth,
                    $left->type->value,
                    $leftTerminal,
                ] <=> [
                    $right->changedSymbolId,
                    $right->depth,
                    $right->type->value,
                    $rightTerminal,
                ];
            },
        );

        return new self($flows);
    }

    /**
     * @return list<AffectedFlow>
     */
    public function all(): array
    {
        return $this->flows;
    }

    public function count(): int
    {
        return count($this->flows);
    }

    public function isEmpty(): bool
    {
        return $this->flows === [];
    }
}
