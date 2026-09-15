<?php

declare(strict_types=1);

namespace Ripple\Analysis\Graph;

final class ReverseDependencyGraphBuilder
{
    public function build(DependencyGraph $graph): ReverseDependencyGraph
    {
        $nodes = [];
        foreach ($graph->getNodes() as $node) {
            $nodes[$node->id] = $node;
        }

        /** @var array<string, array<string, GraphEdge>> $incoming */
        $incoming = [];
        foreach ($graph->getEdges() as $edge) {
            if (!isset($nodes[$edge->source])) {
                $nodes[$edge->source] = GraphNode::unindexed($edge->source);
            }
            if (!isset($nodes[$edge->target])) {
                $nodes[$edge->target] = GraphNode::unindexed($edge->target);
            }

            $key = $edge->source . "\0" . $edge->target . "\0" . $edge->type->value;
            $incoming[$edge->target][$key] = $edge;
        }

        return new ReverseDependencyGraph($nodes, $incoming);
    }
}
