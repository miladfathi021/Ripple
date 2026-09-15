<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Graph\ReverseDependencyGraph;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Git\History\ChurnResult;

final readonly class RiskFactorContext
{
    public function __construct(
        public ChangedSymbolResult $changedSymbols,
        public ReverseDependencyGraph $reverseGraph,
        public TransitiveImpactResult $blastRadius,
        public ChurnResult $churn = new ChurnResult([]),
    ) {
    }

    public static function empty(): self
    {
        return new self(
            ChangedSymbolResult::empty(),
            new ReverseDependencyGraph([], []),
            TransitiveImpactResult::empty(),
            ChurnResult::empty(),
        );
    }

    /**
     * Unique changed-symbol IDs in stable order.
     *
     * @return list<string>
     */
    public function changedSymbolIds(): array
    {
        $ids = [];
        foreach ($this->changedSymbols->changedSymbols as $changedSymbol) {
            $ids[$changedSymbol->symbol->fullyQualifiedName] = true;
        }

        $unique = array_keys($ids);
        sort($unique, SORT_STRING);

        return $unique;
    }
}
