<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

use Ripple\Analysis\Graph\GraphEdge;

final readonly class DirectImpactResult
{
    /**
     * @param list<DirectImpact> $impacts
     */
    public function __construct(
        public array $impacts,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<DirectImpact> $impacts
     */
    public static function fromImpacts(array $impacts): self
    {
        usort(
            $impacts,
            static function (DirectImpact $left, DirectImpact $right): int {
                return [
                    $left->impactedSymbolId,
                    $left->changedSymbolId,
                    $left->edge->type->value,
                ] <=> [
                    $right->impactedSymbolId,
                    $right->changedSymbolId,
                    $right->edge->type->value,
                ];
            },
        );

        return new self($impacts);
    }

    /**
     * Unique impacted symbols, sorted by stable ID.
     *
     * @return list<ImpactedSymbol>
     */
    public function getImpactedSymbols(): array
    {
        /** @var array<string, array{edges: list<GraphEdge>, causedBy: array<string, true>}> $grouped */
        $grouped = [];
        foreach ($this->impacts as $impact) {
            $id = $impact->impactedSymbolId;
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'edges' => [],
                    'causedBy' => [],
                ];
            }
            $grouped[$id]['edges'][] = $impact->edge;
            $grouped[$id]['causedBy'][$impact->changedSymbolId] = true;
        }

        $ids = array_keys($grouped);
        sort($ids, SORT_STRING);

        $symbols = [];
        foreach ($ids as $id) {
            $causedBy = array_keys($grouped[$id]['causedBy']);
            sort($causedBy, SORT_STRING);
            $symbols[] = new ImpactedSymbol($id, $grouped[$id]['edges'], $causedBy);
        }

        return $symbols;
    }

    /**
     * @return list<DirectImpact>
     */
    public function getImpactsForChangedSymbol(string $changedSymbolId): array
    {
        $impacts = [];
        foreach ($this->impacts as $impact) {
            if ($impact->changedSymbolId === $changedSymbolId) {
                $impacts[] = $impact;
            }
        }

        return $impacts;
    }

    public function isEmpty(): bool
    {
        return $this->impacts === [];
    }

    public function count(): int
    {
        return count($this->getImpactedSymbols());
    }
}
