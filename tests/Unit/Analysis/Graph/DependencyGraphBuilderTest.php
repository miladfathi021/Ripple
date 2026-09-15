<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Graph;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\DependencyGraphBuilder;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;

final class DependencyGraphBuilderTest extends TestCase
{
    public function testBuildsNodesAndEdgesFromDependencies(): void
    {
        $source = $this->sourceSymbol();
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency(
                    $source->fullyQualifiedName,
                    'App\\Services\\PaymentService::validate',
                    DependencyType::MethodCall,
                    [35],
                    1,
                ),
            ]),
            [$source],
        );

        $sourceNode = $graph->getNode($source->fullyQualifiedName);
        $this->assertNotNull($sourceNode);
        $this->assertTrue($sourceNode->known);
        $this->assertSame('method', $sourceNode->type);
        $this->assertSame($source->file, $sourceNode->file);

        $this->assertTrue($graph->hasEdge(
            $source->fullyQualifiedName,
            'App\\Services\\PaymentService::validate',
            'method_call',
        ));
        $edge = $graph->getEdges()[0];
        $this->assertSame([35], $edge->lines);
        $this->assertSame(1, $edge->occurrences);
    }

    public function testKeepsEdgesToTargetsThatAreNotIndexed(): void
    {
        $source = $this->sourceSymbol();
        $target = 'Illuminate\\Database\\Eloquent\\Model::save';
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency($source->fullyQualifiedName, $target, DependencyType::MethodCall, [36], 1),
            ]),
            [$source],
        );

        $node = $graph->getNode($target);
        $this->assertNotNull($node);
        $this->assertFalse($node->known);
        $this->assertNull($node->type);
        $this->assertNull($node->file);
        $this->assertSame('save', $node->name);
        $this->assertTrue($graph->hasEdge($source->fullyQualifiedName, $target, 'method_call'));
    }

    public function testDoesNotCollapseDistinctDependencyTypes(): void
    {
        $source = $this->sourceSymbol();
        $target = 'App\\Services\\PaymentService';
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency($source->fullyQualifiedName, $target, DependencyType::ParameterType, [12], 1),
                new Dependency($source->fullyQualifiedName, $target, DependencyType::MethodCall, [35], 1),
            ]),
            [$source],
        );

        $this->assertCount(2, $graph->getEdges());
        $this->assertTrue($graph->hasEdge($source->fullyQualifiedName, $target, 'parameter_type'));
        $this->assertTrue($graph->hasEdge($source->fullyQualifiedName, $target, 'method_call'));
    }

    public function testDoesNotDuplicateNodesWhenTheSameSymbolsAppearRepeatedly(): void
    {
        $source = $this->sourceSymbol();
        $target = 'App\\Services\\PaymentService::validate';
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency($source->fullyQualifiedName, $target, DependencyType::MethodCall, [35], 1),
                new Dependency($source->fullyQualifiedName, $target, DependencyType::MethodCall, [35], 1),
            ]),
            [$source, $source],
        );

        $this->assertCount(2, $graph->getNodes());
        $this->assertCount(1, $graph->getEdges());
    }

    public function testIndexedSymbolsBecomeNodesEvenWithoutDependencies(): void
    {
        $unused = new Symbol(
            SymbolType::Class_,
            'UnusedService',
            'UnusedService',
            'src/UnusedService.php',
            1,
            3,
        );
        $graph = (new DependencyGraphBuilder())->build(DependencyResult::empty(), [$unused]);

        $this->assertCount(1, $graph->getNodes());
        $this->assertTrue($graph->hasNode('UnusedService'));
        $this->assertTrue($graph->getNode('UnusedService')?->known);
        $this->assertSame([], $graph->getEdges());
    }

    public function testOutputOrderIsIndependentOfDependencyInsertionOrder(): void
    {
        $sourceA = $this->symbol('A::run', 'run', 'A.php', 'A');
        $sourceZ = $this->symbol('Z::run', 'run', 'Z.php', 'Z');

        $forward = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency('A::run', 'B', DependencyType::ConstructorCall, [1], 1),
                new Dependency('Z::run', 'A::run', DependencyType::StaticCall, [2], 1),
            ]),
            [$sourceA, $sourceZ],
        );
        $reverse = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency('Z::run', 'A::run', DependencyType::StaticCall, [2], 1),
                new Dependency('A::run', 'B', DependencyType::ConstructorCall, [1], 1),
            ]),
            [$sourceZ, $sourceA],
        );

        $this->assertSame(
            array_map(static fn (GraphNode $node): string => $node->id, $forward->getNodes()),
            array_map(static fn (GraphNode $node): string => $node->id, $reverse->getNodes()),
        );
        $this->assertSame(
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $forward->getEdges(),
            ),
            array_map(
                static fn (GraphEdge $edge): array => [$edge->source, $edge->target, $edge->type->value],
                $reverse->getEdges(),
            ),
        );
    }

    public function testRepresentsASelfDependency(): void
    {
        $source = $this->sourceSymbol();
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult([
                new Dependency(
                    $source->fullyQualifiedName,
                    $source->fullyQualifiedName,
                    DependencyType::MethodCall,
                    [40],
                    1,
                ),
            ]),
            [$source],
        );

        $this->assertCount(1, $graph->getNodes());
        $this->assertTrue($graph->hasEdge(
            $source->fullyQualifiedName,
            $source->fullyQualifiedName,
            'method_call',
        ));
    }

    private function sourceSymbol(): Symbol
    {
        return $this->symbol(
            'App\\Services\\ReservationService::updateStatus',
            'updateStatus',
            'src/Services/ReservationService.php',
            'App\\Services\\ReservationService',
        );
    }

    private function symbol(string $fqn, string $name, string $file, string $parent): Symbol
    {
        return new Symbol(
            SymbolType::Method,
            $name,
            $fqn,
            $file,
            1,
            20,
            $parent,
            'public',
            false,
        );
    }
}
