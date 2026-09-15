<?php

declare(strict_types=1);

namespace Ripple\Analysis\Graph;

use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\Dependencies\DependencyResult;

final class DependencyGraphBuilder
{
    /**
     * @param list<Symbol> $symbols Indexed symbols whose definitions are currently available.
     */
    public function build(DependencyResult $dependencies, array $symbols = []): DependencyGraph
    {
        $graph = new DependencyGraph();
        $known = [];

        foreach ($symbols as $symbol) {
            $known[$symbol->fullyQualifiedName] = $symbol;
            $graph->addNode(GraphNode::fromSymbol($symbol));
        }

        foreach ($dependencies->dependencies as $dependency) {
            $this->registerNode($graph, $known, $dependency->source);
            $this->registerNode($graph, $known, $dependency->target);
            $graph->addEdge(GraphEdge::fromDependency($dependency));
        }

        return $graph;
    }

    /**
     * @param array<string, Symbol> $known
     */
    private function registerNode(DependencyGraph $graph, array $known, string $id): void
    {
        if (isset($known[$id])) {
            $graph->addNode(GraphNode::fromSymbol($known[$id]));

            return;
        }

        $graph->addNode(GraphNode::unindexed($id));
    }
}
