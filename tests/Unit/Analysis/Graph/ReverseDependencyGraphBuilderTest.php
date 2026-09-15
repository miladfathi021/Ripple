<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Graph;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;

final class ReverseDependencyGraphBuilderTest extends TestCase
{
    public function testBuildDoesNotMutateTheForwardGraph(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('A'));
        $graph->addNode(GraphNode::unindexed('B'));
        $graph->addEdge(new GraphEdge('A', 'B', DependencyType::MethodCall, [1], 1));

        $nodeIds = array_map(static fn (GraphNode $node): string => $node->id, $graph->getNodes());
        $edgeTuples = array_map(
            static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value, $edge->lines, $edge->occurrences],
            $graph->getEdges(),
        );

        $reverse = (new ReverseDependencyGraphBuilder())->build($graph);
        $graph->addNode(GraphNode::unindexed('C'));
        $graph->addEdge(new GraphEdge('A', 'C', DependencyType::StaticCall, [2], 1));

        $this->assertSame(
            $nodeIds,
            array_map(static fn (GraphNode $node): string => $node->id, $reverse->getNodes()),
        );
        $this->assertSame(['A'], $reverse->getDependents('B'));
        $this->assertSame([], $reverse->getDependents('C'));
        $this->assertFalse($reverse->hasNode('C'));

        $this->assertSame(
            ['A', 'B', 'C'],
            array_map(static fn (GraphNode $node): string => $node->id, $graph->getNodes()),
        );
        $this->assertSame(
            [
                ['A', 'B', 'method_call', [1], 1],
                ['A', 'C', 'static_call', [2], 1],
            ],
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value, $edge->lines, $edge->occurrences],
                $graph->getEdges(),
            ),
        );
        $this->assertSame($edgeTuples[0], [
            $graph->getEdges()[0]->source,
            $graph->getEdges()[0]->target,
            $graph->getEdges()[0]->type->value,
            $graph->getEdges()[0]->lines,
            $graph->getEdges()[0]->occurrences,
        ]);
    }

    public function testBuildIndexesIncomingEdgesWithoutReversingSourceAndTarget(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('A'));
        $graph->addNode(GraphNode::unindexed('B'));
        $graph->addNode(GraphNode::unindexed('C'));
        $graph->addEdge(new GraphEdge('A', 'B', DependencyType::MethodCall, [1], 1));
        $graph->addEdge(new GraphEdge('A', 'C', DependencyType::ParameterType, [2], 1));
        $graph->addEdge(new GraphEdge('B', 'C', DependencyType::StaticCall, [3], 1));

        $reverse = (new ReverseDependencyGraphBuilder())->build($graph);

        $this->assertSame(['A'], $reverse->getDependents('B'));
        $this->assertSame(['A', 'B'], $reverse->getDependents('C'));
        $this->assertSame('A', $reverse->getDependentEdges('B')[0]->source);
        $this->assertSame('B', $reverse->getDependentEdges('B')[0]->target);
        $this->assertSame('A', $reverse->getDependentEdges('C')[0]->source);
        $this->assertSame('C', $reverse->getDependentEdges('C')[0]->target);
        $this->assertSame('B', $reverse->getDependentEdges('C')[1]->source);
        $this->assertSame('C', $reverse->getDependentEdges('C')[1]->target);
    }
}
