<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\ReverseDependencyGraph;

final class TransitiveImpactAnalyzer
{
    /**
     * BFS over reverse-dependency edges from each changed symbol.
     * Does not rebuild the graph. Traverses only the reachable blast radius.
     */
    public function analyze(
        ChangedSymbolResult $changedSymbols,
        ReverseDependencyGraph $reverseGraph,
    ): TransitiveImpactResult {
        $changedIds = [];
        foreach ($changedSymbols->changedSymbols as $changedSymbol) {
            $changedIds[$changedSymbol->symbol->fullyQualifiedName] = true;
        }

        $originIds = array_keys($changedIds);
        sort($originIds, SORT_STRING);

        /** @var array<string, array<string, array{depth: int, edges: array<string, GraphEdge>}>> $reaches */
        $reaches = [];
        foreach ($originIds as $originId) {
            $this->collectFromOrigin($originId, $reverseGraph, $reaches);
        }

        $entries = [];
        foreach ($reaches as $impactedId => $byOrigin) {
            if (isset($changedIds[$impactedId])) {
                continue;
            }

            $origins = [];
            $minDepth = PHP_INT_MAX;
            foreach ($byOrigin as $originId => $data) {
                $minDepth = min($minDepth, $data['depth']);
                foreach ($data['edges'] as $edge) {
                    $origins[] = new BlastRadiusOrigin($originId, $data['depth'], $edge);
                }
            }

            $entries[] = new BlastRadiusEntry($impactedId, $minDepth, $origins);
        }

        return TransitiveImpactResult::fromEntries($entries);
    }

    /**
     * @param array<string, array<string, array{depth: int, edges: array<string, GraphEdge>}>> $reaches
     */
    private function collectFromOrigin(
        string $originId,
        ReverseDependencyGraph $reverseGraph,
        array &$reaches,
    ): void {
        $queue = [$originId];
        $head = 0;
        $depth = [$originId => 0];
        $visited = [$originId => true];

        while ($head < count($queue)) {
            $current = $queue[$head++];
            $currentDepth = $depth[$current];

            foreach ($reverseGraph->getDependentEdges($current) as $edge) {
                $dependent = $edge->source;
                if ($dependent === $current) {
                    continue;
                }

                $nextDepth = $currentDepth + 1;
                if ($dependent !== $originId) {
                    $this->recordReach($reaches, $dependent, $originId, $nextDepth, $edge);
                }

                if (isset($visited[$dependent])) {
                    continue;
                }

                $visited[$dependent] = true;
                $depth[$dependent] = $nextDepth;
                $queue[] = $dependent;
            }
        }
    }

    /**
     * @param array<string, array<string, array{depth: int, edges: array<string, GraphEdge>}>> $reaches
     */
    private function recordReach(
        array &$reaches,
        string $impactedId,
        string $originId,
        int $nextDepth,
        GraphEdge $edge,
    ): void {
        if (!isset($reaches[$impactedId][$originId])) {
            $reaches[$impactedId][$originId] = [
                'depth' => $nextDepth,
                'edges' => [$this->edgeKey($edge) => $edge],
            ];

            return;
        }

        $existing = &$reaches[$impactedId][$originId];
        if ($nextDepth < $existing['depth']) {
            $existing = [
                'depth' => $nextDepth,
                'edges' => [$this->edgeKey($edge) => $edge],
            ];

            return;
        }

        if ($nextDepth === $existing['depth']) {
            $existing['edges'][$this->edgeKey($edge)] = $edge;
        }
    }

    private function edgeKey(GraphEdge $edge): string
    {
        return $edge->source . "\0" . $edge->target . "\0" . $edge->type->value;
    }
}
