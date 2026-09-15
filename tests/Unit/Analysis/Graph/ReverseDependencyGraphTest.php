<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Graph;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;
use Ripple\Analysis\Graph\ReverseDependencyGraph;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;

final class ReverseDependencyGraphTest extends TestCase
{
    public function testGetDependentsReturnsTheDirectSource(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'B', DependencyType::MethodCall),
        ]);

        $this->assertSame(['A'], $reverse->getDependents('B'));
        $this->assertTrue($reverse->hasDependents('B'));
        $this->assertTrue($reverse->hasNode('A'));
        $this->assertTrue($reverse->hasNode('B'));
    }

    public function testGetDependentsReturnsMultipleDirectSourcesInStableOrder(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('D', 'C', DependencyType::MethodCall),
            $this->edge('A', 'C', DependencyType::MethodCall),
            $this->edge('B', 'C', DependencyType::MethodCall),
        ]);

        $this->assertSame(['A', 'B', 'D'], $reverse->getDependents('C'));
    }

    public function testNodeWithOnlyOutgoingEdgesHasNoDependents(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'B', DependencyType::MethodCall),
        ]);

        $this->assertSame([], $reverse->getDependents('A'));
        $this->assertFalse($reverse->hasDependents('A'));
    }

    public function testUnknownNodeLookupReturnsAnEmptyCollection(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'B', DependencyType::MethodCall),
        ]);

        $this->assertSame([], $reverse->getDependents('DoesNotExist'));
        $this->assertFalse($reverse->hasDependents('DoesNotExist'));
        $this->assertFalse($reverse->hasNode('DoesNotExist'));
        $this->assertSame([], $reverse->getDependentEdges('DoesNotExist'));
    }

    public function testUnindexedTargetsAreQueryableLikeKnownNodes(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::fromSymbol(new Symbol(
            SymbolType::Method,
            'updateStatus',
            'A',
            'src/A.php',
            1,
            10,
            'ReservationService',
            'public',
            false,
        )));
        $graph->addNode(GraphNode::unindexed('UnknownService::validate'));
        $graph->addEdge($this->edge('A', 'UnknownService::validate', DependencyType::MethodCall, [36]));

        $reverse = (new ReverseDependencyGraphBuilder())->build($graph);
        $unknown = null;
        foreach ($reverse->getNodes() as $node) {
            if ($node->id === 'UnknownService::validate') {
                $unknown = $node;
            }
        }

        $this->assertNotNull($unknown);
        $this->assertFalse($unknown->known);
        $this->assertTrue($reverse->hasNode('UnknownService::validate'));
        $this->assertSame(['A'], $reverse->getDependents('UnknownService::validate'));
    }

    public function testDistinctDependencyTypesRemainAvailableThroughEdgeLookup(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'B', DependencyType::MethodCall, [35]),
            $this->edge('A', 'B', DependencyType::ParameterType, [12]),
        ]);

        $this->assertSame(['A'], $reverse->getDependents('B'));
        $edges = $reverse->getDependentEdges('B');
        $this->assertCount(2, $edges);
        $this->assertSame(DependencyType::MethodCall, $edges[0]->type);
        $this->assertSame(DependencyType::ParameterType, $edges[1]->type);
        $this->assertSame('A', $edges[0]->source);
        $this->assertSame('B', $edges[0]->target);
        $this->assertSame('A', $edges[1]->source);
        $this->assertSame('B', $edges[1]->target);
    }

    public function testDependentEdgesPreserveOriginalMetadata(): void
    {
        $reverse = $this->reverseGraph([
            new GraphEdge('A', 'B', DependencyType::MethodCall, [35], 2),
        ]);

        $edge = $reverse->getDependentEdges('B')[0];
        $this->assertSame('A', $edge->source);
        $this->assertSame('B', $edge->target);
        $this->assertSame(DependencyType::MethodCall, $edge->type);
        $this->assertSame([35], $edge->lines);
        $this->assertSame(2, $edge->occurrences);
    }

    public function testDoesNotIntroduceDuplicateRelationships(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('A'));
        $graph->addNode(GraphNode::unindexed('B'));
        $graph->addEdge($this->edge('A', 'B', DependencyType::MethodCall, [35], 1));
        $graph->addEdge($this->edge('A', 'B', DependencyType::MethodCall, [40], 3));

        $reverse = (new ReverseDependencyGraphBuilder())->build($graph);

        $this->assertSame(['A'], $reverse->getDependents('B'));
        $this->assertCount(1, $reverse->getDependentEdges('B'));
        $this->assertSame([35], $reverse->getDependentEdges('B')[0]->lines);
        $this->assertSame(1, $reverse->getDependentEdges('B')[0]->occurrences);
    }

    public function testRepresentsASelfEdge(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'A', DependencyType::MethodCall, [40]),
        ]);

        $this->assertSame(['A'], $reverse->getDependents('A'));
        $this->assertTrue($reverse->hasDependents('A'));
        $edge = $reverse->getDependentEdges('A')[0];
        $this->assertSame('A', $edge->source);
        $this->assertSame('A', $edge->target);
    }

    public function testDoesNotReturnTransitiveDependents(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'B', DependencyType::MethodCall),
            $this->edge('B', 'C', DependencyType::MethodCall),
            $this->edge('C', 'D', DependencyType::MethodCall),
        ]);

        $this->assertSame(['C'], $reverse->getDependents('D'));
        $this->assertSame(['B'], $reverse->getDependents('C'));
        $this->assertSame(['A'], $reverse->getDependents('B'));
        $this->assertSame([], $reverse->getDependents('A'));
    }

    public function testDependentOrderIsIndependentOfInsertionOrder(): void
    {
        $first = $this->reverseGraph([
            $this->edge('Z', 'C', DependencyType::StaticCall),
            $this->edge('A', 'C', DependencyType::MethodCall),
            $this->edge('B', 'C', DependencyType::ConstructorCall),
        ]);
        $second = $this->reverseGraph([
            $this->edge('B', 'C', DependencyType::ConstructorCall),
            $this->edge('A', 'C', DependencyType::MethodCall),
            $this->edge('Z', 'C', DependencyType::StaticCall),
        ]);

        $this->assertSame(['A', 'B', 'Z'], $first->getDependents('C'));
        $this->assertSame($first->getDependents('C'), $second->getDependents('C'));
        $this->assertSame(
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $first->getDependentEdges('C'),
            ),
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $second->getDependentEdges('C'),
            ),
        );
        $this->assertSame(
            [
                ['A', 'C', 'method_call'],
                ['B', 'C', 'constructor_call'],
                ['Z', 'C', 'static_call'],
            ],
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $first->getDependentEdges('C'),
            ),
        );
    }

    public function testReturnedCollectionsCannotMutateInternalStorage(): void
    {
        $reverse = $this->reverseGraph([
            $this->edge('A', 'B', DependencyType::MethodCall),
        ]);

        $dependents = $reverse->getDependents('B');
        $dependents[] = 'Z';
        $nodes = $reverse->getNodes();
        $nodes[] = GraphNode::unindexed('Z');
        $edges = $reverse->getDependentEdges('B');
        $edges[] = $this->edge('Z', 'B', DependencyType::StaticCall);

        $this->assertSame(['A'], $reverse->getDependents('B'));
        $this->assertFalse($reverse->hasNode('Z'));
        $this->assertCount(1, $reverse->getDependentEdges('B'));
    }

    public function testEmptyGraphLookupsAreSafe(): void
    {
        $reverse = new ReverseDependencyGraph([], []);

        $this->assertSame([], $reverse->getDependents('A'));
        $this->assertSame([], $reverse->getNodes());
        $this->assertFalse($reverse->hasNode('A'));
        $this->assertFalse($reverse->hasDependents('A'));
    }

    /**
     * @param list<GraphEdge> $edges
     */
    private function reverseGraph(array $edges): ReverseDependencyGraph
    {
        $graph = new DependencyGraph();
        foreach ($edges as $edge) {
            $graph->addNode(GraphNode::unindexed($edge->source));
            $graph->addNode(GraphNode::unindexed($edge->target));
            $graph->addEdge($edge);
        }

        return (new ReverseDependencyGraphBuilder())->build($graph);
    }

    /**
     * @param list<int> $lines
     */
    private function edge(
        string $source,
        string $target,
        DependencyType $type,
        array $lines = [1],
        int $occurrences = 1,
    ): GraphEdge {
        return new GraphEdge($source, $target, $type, $lines, $occurrences);
    }
}
