<?php

declare(strict_types=1);

namespace Ripple\Analysis\Graph;

final class ReverseDependencyGraph
{
    /**
     * @param array<string, GraphNode> $nodes
     * @param array<string, array<string, GraphEdge>> $incoming
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $incoming,
    ) {
    }

    public function hasNode(string $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    public function hasDependents(string $nodeId): bool
    {
        return ($this->incoming[$nodeId] ?? []) !== [];
    }

    /**
     * Direct dependents of a symbol: sources of incoming edges.
     *
     * @return list<string>
     */
    public function getDependents(string $nodeId): array
    {
        $ids = [];
        foreach ($this->getDependentEdges($nodeId) as $edge) {
            $ids[$edge->source] = true;
        }

        $dependents = array_keys($ids);
        sort($dependents, SORT_STRING);

        return $dependents;
    }

    /**
     * Original forward edges whose target is $nodeId.
     *
     * @return list<GraphEdge>
     */
    public function getDependentEdges(string $nodeId): array
    {
        $edges = array_values($this->incoming[$nodeId] ?? []);
        usort(
            $edges,
            static function (GraphEdge $left, GraphEdge $right): int {
                return [$left->source, $left->target, $left->type->value]
                    <=> [$right->source, $right->target, $right->type->value];
            },
        );

        return $edges;
    }

    /**
     * @return list<GraphNode>
     */
    public function getNodes(): array
    {
        $nodes = array_values($this->nodes);
        usort(
            $nodes,
            static fn (GraphNode $left, GraphNode $right): int => $left->id <=> $right->id,
        );

        return $nodes;
    }
}
