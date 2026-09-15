<?php

declare(strict_types=1);

namespace Ripple\Analysis\Graph;

final class DependencyGraph
{
    /** @var array<string, GraphNode> */
    private array $nodes = [];

    /** @var array<string, GraphEdge> */
    private array $edges = [];

    public function addNode(GraphNode $node): void
    {
        $existing = $this->nodes[$node->id] ?? null;
        if ($existing === null) {
            $this->nodes[$node->id] = $node;

            return;
        }

        if (!$existing->known && $node->known) {
            $this->nodes[$node->id] = $node;
        }
    }

    public function addEdge(GraphEdge $edge): void
    {
        $key = self::edgeKey($edge->source, $edge->target, $edge->type->value);
        if (isset($this->edges[$key])) {
            return;
        }

        $this->edges[$key] = $edge;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function getNode(string $id): ?GraphNode
    {
        return $this->nodes[$id] ?? null;
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

    /**
     * @return list<GraphEdge>
     */
    public function getEdges(): array
    {
        $edges = array_values($this->edges);
        usort(
            $edges,
            static function (GraphEdge $left, GraphEdge $right): int {
                return [$left->source, $left->target, $left->type->value]
                    <=> [$right->source, $right->target, $right->type->value];
            },
        );

        return $edges;
    }

    public function hasEdge(string $source, string $target, string $type): bool
    {
        return isset($this->edges[self::edgeKey($source, $target, $type)]);
    }

    private static function edgeKey(string $source, string $target, string $type): string
    {
        return $source . "\0" . $target . "\0" . $type;
    }
}
