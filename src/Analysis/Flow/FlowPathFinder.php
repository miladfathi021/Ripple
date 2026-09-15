<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;

final class FlowPathFinder
{
    /**
     * Shortest downstream dependency paths from $originId, truncated at $maxDepth.
     *
     * @return list<FlowPath>
     */
    public function find(string $originId, DependencyGraph $graph, FlowDepth $maxDepth): array
    {
        $outgoing = $this->outgoingIndex($graph);
        $queue = [$originId];
        $head = 0;
        $depth = [$originId => 0];
        $visited = [$originId => true];
        /** @var array<string, GraphEdge> $parentEdge */
        $parentEdge = [];

        while ($head < count($queue)) {
            $current = $queue[$head++];
            if ($depth[$current] >= $maxDepth->value) {
                continue;
            }

            foreach ($outgoing[$current] ?? [] as $edge) {
                $next = $edge->target;
                if (isset($visited[$next])) {
                    continue;
                }

                $visited[$next] = true;
                $depth[$next] = $depth[$current] + 1;
                $parentEdge[$next] = $edge;
                $queue[] = $next;
            }
        }

        $treeChildren = [];
        foreach ($parentEdge as $nodeId => $edge) {
            $treeChildren[$edge->source][] = $nodeId;
        }

        $leaves = [];
        foreach (array_keys($visited) as $nodeId) {
            if ($nodeId === $originId) {
                continue;
            }
            if (!isset($treeChildren[$nodeId])) {
                $leaves[] = $nodeId;
            }
        }
        sort($leaves, SORT_STRING);

        $paths = [];
        foreach ($leaves as $leaf) {
            $paths[] = new FlowPath($originId, $this->edgesTo($leaf, $originId, $parentEdge));
        }

        return $paths;
    }

    /**
     * @return array<string, list<GraphEdge>>
     */
    private function outgoingIndex(DependencyGraph $graph): array
    {
        $outgoing = [];
        foreach ($graph->getEdges() as $edge) {
            $outgoing[$edge->source][] = $edge;
        }

        foreach ($outgoing as &$edges) {
            usort(
                $edges,
                static function (GraphEdge $left, GraphEdge $right): int {
                    return [$left->target, $left->type->value]
                        <=> [$right->target, $right->type->value];
                },
            );
        }
        unset($edges);

        return $outgoing;
    }

    /**
     * @param array<string, GraphEdge> $parentEdge
     * @return list<GraphEdge>
     */
    private function edgesTo(string $leaf, string $originId, array $parentEdge): array
    {
        $edges = [];
        $node = $leaf;
        while ($node !== $originId) {
            $edge = $parentEdge[$node];
            $edges[] = $edge;
            $node = $edge->source;
        }

        return array_reverse($edges);
    }
}
