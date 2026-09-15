<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Graph;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;

final class DependencyGraphTest extends TestCase
{
    public function testDuplicateSymbolsProduceOneNode(): void
    {
        $node = GraphNode::fromSymbol($this->methodSymbol());
        $graph = new DependencyGraph();
        $graph->addNode($node);
        $graph->addNode($node);
        $graph->addNode(GraphNode::fromSymbol($this->methodSymbol()));

        $this->assertCount(1, $graph->getNodes());
        $this->assertTrue($graph->hasNode($node->id));
        $this->assertSame($node->id, $graph->getNode($node->id)?->id);
    }

    public function testKnownNodeReplacesAnUnindexedNodeWithTheSameId(): void
    {
        $symbol = $this->methodSymbol();
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed($symbol->fullyQualifiedName));
        $graph->addNode(GraphNode::fromSymbol($symbol));

        $node = $graph->getNode($symbol->fullyQualifiedName);
        $this->assertNotNull($node);
        $this->assertTrue($node->known);
        $this->assertSame('method', $node->type);
        $this->assertSame($symbol->file, $node->file);
    }

    public function testCreatesDirectedEdgesAndPreservesDependencyMetadata(): void
    {
        $edge = $this->methodCallEdge();
        $graph = new DependencyGraph();
        $graph->addEdge($edge);

        $this->assertCount(1, $graph->getEdges());
        $this->assertTrue($graph->hasEdge($edge->source, $edge->target, 'method_call'));
        $this->assertSame($edge->source, $graph->getEdges()[0]->source);
        $this->assertSame($edge->target, $graph->getEdges()[0]->target);
        $this->assertSame(DependencyType::MethodCall, $graph->getEdges()[0]->type);
        $this->assertSame([35], $graph->getEdges()[0]->lines);
        $this->assertSame(1, $graph->getEdges()[0]->occurrences);
    }

    public function testDoesNotCreateDuplicateEdgesOrReaggregateOccurrences(): void
    {
        $graph = new DependencyGraph();
        $graph->addEdge($this->methodCallEdge([35], 1));
        $graph->addEdge($this->methodCallEdge([35, 40], 2));

        $this->assertCount(1, $graph->getEdges());
        $this->assertSame([35], $graph->getEdges()[0]->lines);
        $this->assertSame(1, $graph->getEdges()[0]->occurrences);
    }

    public function testDistinctDependencyTypesRemainSeparateEdges(): void
    {
        $source = 'App\\Services\\ReservationService::updateStatus';
        $target = 'App\\Services\\PaymentService';
        $graph = new DependencyGraph();
        $graph->addEdge(new GraphEdge($source, $target, DependencyType::MethodCall, [35], 1));
        $graph->addEdge(new GraphEdge($source, $target, DependencyType::ParameterType, [12], 1));

        $this->assertCount(2, $graph->getEdges());
        $this->assertTrue($graph->hasEdge($source, $target, 'method_call'));
        $this->assertTrue($graph->hasEdge($source, $target, 'parameter_type'));
    }

    public function testRepresentsASelfDependency(): void
    {
        $id = 'App\\Services\\ReservationService::updateStatus';
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed($id));
        $graph->addEdge(new GraphEdge($id, $id, DependencyType::MethodCall, [40], 1));

        $this->assertCount(1, $graph->getNodes());
        $this->assertCount(1, $graph->getEdges());
        $this->assertTrue($graph->hasEdge($id, $id, 'method_call'));
    }

    public function testCollectionsAreDeterministicRegardlessOfInsertionOrder(): void
    {
        $first = $this->graphWithZThenA();
        $second = $this->graphWithAThenZ();

        $this->assertSame(
            array_map(static fn (GraphNode $node): string => $node->id, $first->getNodes()),
            array_map(static fn (GraphNode $node): string => $node->id, $second->getNodes()),
        );
        $this->assertSame(
            ['A', 'B', 'Z'],
            array_map(static fn (GraphNode $node): string => $node->id, $first->getNodes()),
        );
        $this->assertSame(
            [
                ['Z', 'A', 'static_call'],
                ['Z', 'B', 'method_call'],
            ],
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $first->getEdges(),
            ),
        );
        $this->assertSame(
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $first->getEdges(),
            ),
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $second->getEdges(),
            ),
        );
    }

    public function testReturnedCollectionsCannotMutateInternalStorage(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('A'));

        $nodes = $graph->getNodes();
        $nodes[] = GraphNode::unindexed('B');

        $this->assertCount(1, $graph->getNodes());
        $this->assertFalse($graph->hasNode('B'));
    }

    private function methodSymbol(): Symbol
    {
        return new Symbol(
            SymbolType::Method,
            'updateStatus',
            'App\\Services\\ReservationService::updateStatus',
            'src/Services/ReservationService.php',
            30,
            40,
            'App\\Services\\ReservationService',
            'public',
            false,
        );
    }

    /**
     * @param list<int> $lines
     */
    private function methodCallEdge(array $lines = [35], int $occurrences = 1): GraphEdge
    {
        return new GraphEdge(
            'App\\Services\\ReservationService::updateStatus',
            'App\\Services\\PaymentService::validate',
            DependencyType::MethodCall,
            $lines,
            $occurrences,
        );
    }

    private function graphWithZThenA(): DependencyGraph
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('Z'));
        $graph->addNode(GraphNode::unindexed('A'));
        $graph->addNode(GraphNode::unindexed('B'));
        $graph->addEdge(new GraphEdge('Z', 'B', DependencyType::MethodCall, [2], 1));
        $graph->addEdge(new GraphEdge('Z', 'A', DependencyType::StaticCall, [1], 1));

        return $graph;
    }

    private function graphWithAThenZ(): DependencyGraph
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('A'));
        $graph->addNode(GraphNode::unindexed('B'));
        $graph->addNode(GraphNode::unindexed('Z'));
        $graph->addEdge(new GraphEdge('Z', 'A', DependencyType::StaticCall, [1], 1));
        $graph->addEdge(new GraphEdge('Z', 'B', DependencyType::MethodCall, [2], 1));

        return $graph;
    }
}
