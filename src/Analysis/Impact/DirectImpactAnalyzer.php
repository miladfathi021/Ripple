<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Graph\ReverseDependencyGraph;

final class DirectImpactAnalyzer
{
    /**
     * One reverse-graph lookup per changed symbol. No traversal.
     */
    public function analyze(
        ChangedSymbolResult $changedSymbols,
        ReverseDependencyGraph $reverseGraph,
    ): DirectImpactResult {
        $impacts = [];
        foreach ($changedSymbols->changedSymbols as $changedSymbol) {
            $changedId = $changedSymbol->symbol->fullyQualifiedName;
            foreach ($reverseGraph->getDependentEdges($changedId) as $edge) {
                if ($edge->source === $changedId) {
                    continue;
                }

                $impacts[] = new DirectImpact(
                    changedSymbolId: $changedId,
                    impactedSymbolId: $edge->source,
                    edge: $edge,
                );
            }
        }

        return DirectImpactResult::fromImpacts($impacts);
    }
}
