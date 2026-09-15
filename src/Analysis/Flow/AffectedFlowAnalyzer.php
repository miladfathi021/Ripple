<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphNode;

final class AffectedFlowAnalyzer
{
    public function __construct(
        private readonly FlowPathFinder $pathFinder = new FlowPathFinder(),
        private readonly FlowDepth $maxDepth = new FlowDepth(FlowDepth::DEFAULT),
    ) {
    }

    public function analyze(
        ChangedSymbolResult $changedSymbols,
        DependencyGraph $graph,
    ): AffectedFlowResult {
        $originIds = [];
        foreach ($changedSymbols->changedSymbols as $changedSymbol) {
            $originIds[$changedSymbol->symbol->fullyQualifiedName] = true;
        }
        $origins = array_keys($originIds);
        sort($origins, SORT_STRING);

        $flows = [];
        foreach ($origins as $originId) {
            foreach ($this->pathFinder->find($originId, $graph, $this->maxDepth) as $path) {
                $flows[] = $this->toFlow($path, $graph);
            }
        }

        return AffectedFlowResult::fromFlows($flows);
    }

    private function toFlow(FlowPath $path, DependencyGraph $graph): AffectedFlow
    {
        $nodes = [];
        $depth = 0;
        foreach ($path->edges as $edge) {
            $depth++;
            $graphNode = $graph->getNode($edge->target) ?? GraphNode::unindexed($edge->target);
            $nodes[] = new FlowNode(
                id: $edge->target,
                depth: $depth,
                known: $graphNode->known,
            );
        }

        return new AffectedFlow(
            changedSymbolId: $path->originId,
            type: FlowType::fromDependencyType($path->edges[0]->type),
            nodes: $nodes,
            edges: $path->edges,
            depth: $path->depth(),
        );
    }
}
