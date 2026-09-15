<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Flow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Flow\AffectedFlow;
use Ripple\Analysis\Flow\AffectedFlowAnalyzer;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Flow\FlowDepth;
use Ripple\Analysis\Flow\FlowType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;

final class AffectedFlowAnalyzerTest extends TestCase
{
    public function testBasicDownstreamChain(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('A', 'B', DependencyType::MethodCall),
                $this->edge('B', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(
            [
                ['A', 'call_chain', 2, ['B', 'C']],
            ],
            $this->snapshots($result),
        );
        $this->assertSame(1, $result->all()[0]->nodes[0]->depth);
        $this->assertSame(2, $result->all()[0]->nodes[1]->depth);
        $this->assertSame('A', $result->all()[0]->edges[0]->source);
        $this->assertSame('B', $result->all()[0]->edges[0]->target);
        $this->assertSame(DependencyType::MethodCall, $result->all()[0]->edges[0]->type);
        $this->assertSame([10], $result->all()[0]->edges[0]->lines);
        $this->assertSame(1, $result->all()[0]->edges[0]->occurrences);
    }

    public function testMaximumDepthTruncatesThePath(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('A', 'B', DependencyType::MethodCall),
                $this->edge('B', 'C', DependencyType::MethodCall),
                $this->edge('C', 'D', DependencyType::MethodCall),
                $this->edge('D', 'E', DependencyType::MethodCall),
            ],
            2,
        );

        $this->assertSame(
            [
                ['A', 'call_chain', 2, ['B', 'C']],
            ],
            $this->snapshots($result),
        );
        $this->assertSame(['B', 'C'], array_map(static fn ($node): string => $node->id, $result->all()[0]->nodes));
    }

    public function testCycleTerminatesWithoutRepeatingTheOrigin(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('A', 'B', DependencyType::MethodCall),
                $this->edge('B', 'C', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(
            [
                ['A', 'call_chain', 2, ['B', 'C']],
            ],
            $this->snapshots($result),
        );
        $this->assertNotContains('A', array_map(static fn ($node): string => $node->id, $result->all()[0]->nodes));
    }

    public function testBranchingProducesBothPaths(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('A', 'B', DependencyType::MethodCall),
                $this->edge('A', 'C', DependencyType::MethodCall),
                $this->edge('B', 'D', DependencyType::MethodCall),
                $this->edge('C', 'E', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(
            [
                ['A', 'call_chain', 2, ['B', 'D']],
                ['A', 'call_chain', 2, ['C', 'E']],
            ],
            $this->snapshots($result),
        );
    }

    public function testMultiplePathsToTheSameNodeKeepTheShortestDeterministicPath(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('A', 'B', DependencyType::MethodCall),
                $this->edge('A', 'C', DependencyType::MethodCall),
                $this->edge('B', 'D', DependencyType::MethodCall),
                $this->edge('C', 'D', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(
            [
                ['A', 'call_chain', 1, ['C']],
                ['A', 'call_chain', 2, ['B', 'D']],
            ],
            $this->snapshots($result),
        );
        $terminals = [];
        foreach ($result->all() as $flow) {
            $terminals[] = $flow->nodes[array_key_last($flow->nodes)]->id;
        }
        $this->assertCount(1, array_keys(array_flip(array_filter($terminals, static fn (string $id): bool => $id === 'D'))));
    }

    public function testMultipleChangedSymbolsPreserveSeparateOrigins(): void
    {
        $result = $this->analyze(
            ['A', 'B'],
            [
                $this->edge('A', 'C', DependencyType::MethodCall),
                $this->edge('B', 'C', DependencyType::StaticCall),
            ],
        );

        $this->assertSame(
            [
                ['A', 'call_chain', 1, ['C']],
                ['B', 'call_chain', 1, ['C']],
            ],
            $this->snapshots($result),
        );
        $this->assertSame('A', $result->all()[0]->changedSymbolId);
        $this->assertSame('B', $result->all()[1]->changedSymbolId);
        $this->assertSame('C', $result->all()[0]->nodes[0]->id);
        $this->assertSame('C', $result->all()[1]->nodes[0]->id);
    }

    public function testUnknownGraphNodesDoNotCrash(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::fromSymbol(new Symbol(
            SymbolType::Method,
            'run',
            'A',
            'src/A.php',
            1,
            10,
        )));
        $graph->addEdge($this->edge('A', 'External\\Vendor::call', DependencyType::StaticCall));

        $result = (new AffectedFlowAnalyzer())->analyze(
            $this->changedSymbols(['A']),
            $graph,
        );

        $this->assertCount(1, $result->all());
        $this->assertSame('External\\Vendor::call', $result->all()[0]->nodes[0]->id);
        $this->assertFalse($result->all()[0]->nodes[0]->known);
        $this->assertSame(FlowType::CallChain, $result->all()[0]->type);
    }

    public function testEmptyGraphHasNoFlows(): void
    {
        $this->assertTrue($this->analyze(['A'], [])->isEmpty());
    }

    public function testIsolatedChangedSymbolHasNoFlows(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('A'));
        $graph->addNode(GraphNode::unindexed('B'));
        $graph->addEdge($this->edge('B', 'C', DependencyType::MethodCall));

        $result = (new AffectedFlowAnalyzer())->analyze($this->changedSymbols(['A']), $graph);

        $this->assertTrue($result->isEmpty());
    }

    public function testZeroDepthProducesNoFlows(): void
    {
        $result = $this->analyze(
            ['A'],
            [$this->edge('A', 'B', DependencyType::MethodCall)],
            0,
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testSelfEdgeDoesNotLoop(): void
    {
        $result = $this->analyze(
            ['A'],
            [$this->edge('A', 'A', DependencyType::MethodCall)],
        );

        $this->assertTrue($result->isEmpty());
    }

    #[DataProvider('dependencyTypeMappings')]
    public function testEachDependencyTypeProducesTheMappedFlowType(
        DependencyType $dependencyType,
        FlowType $expected,
    ): void {
        $result = $this->analyze(
            ['A'],
            [$this->edge('A', 'B', $dependencyType)],
        );

        $this->assertCount(1, $result->all());
        $this->assertSame($expected, $result->all()[0]->type);
        $this->assertSame($dependencyType, $result->all()[0]->edges[0]->type);
        $this->assertSame(['B'], array_map(static fn ($node): string => $node->id, $result->all()[0]->nodes));
    }

    /**
     * @return array<string, array{DependencyType, FlowType}>
     */
    public static function dependencyTypeMappings(): array
    {
        return FlowTypeTest::dependencyTypeMappings();
    }

    public function testFlowTypeFollowsTheFirstEdge(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('A', 'B', DependencyType::ConstructorCall),
                $this->edge('B', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(FlowType::ConstructionChain, $result->all()[0]->type);
        $this->assertSame(['B', 'C'], array_map(static fn ($node): string => $node->id, $result->all()[0]->nodes));
    }

    public function testOrderingIsDeterministicAcrossRepeatedRuns(): void
    {
        $edges = [
            $this->edge('Z', 'M', DependencyType::Extends),
            $this->edge('A', 'C', DependencyType::MethodCall),
            $this->edge('A', 'B', DependencyType::ParameterType),
            $this->edge('B', 'D', DependencyType::MethodCall),
        ];

        $first = $this->analyze(['Z', 'A'], $edges);
        $second = $this->analyze(['A', 'Z'], array_reverse($edges));

        $this->assertSame($this->snapshots($first), $this->snapshots($second));
        $this->assertSame(
            [
                ['A', 'call_chain', 1, ['C']],
                ['A', 'type_dependency_chain', 2, ['B', 'D']],
                ['Z', 'inheritance_chain', 1, ['M']],
            ],
            $this->snapshots($first),
        );
    }

    /**
     * @param list<string> $changedIds
     * @param list<GraphEdge> $edges
     */
    private function analyze(array $changedIds, array $edges, int $maxDepth = 3): AffectedFlowResult
    {
        $graph = new DependencyGraph();
        foreach ($edges as $edge) {
            $graph->addNode(GraphNode::unindexed($edge->source));
            $graph->addNode(GraphNode::unindexed($edge->target));
            $graph->addEdge($edge);
        }

        return (new AffectedFlowAnalyzer(maxDepth: new FlowDepth($maxDepth)))->analyze(
            $this->changedSymbols($changedIds),
            $graph,
        );
    }

    /**
     * @return list<array{string, string, int, list<string>}>
     */
    private function snapshots(AffectedFlowResult $result): array
    {
        return array_map(
            static function (AffectedFlow $flow): array {
                return [
                    $flow->changedSymbolId,
                    $flow->type->value,
                    $flow->depth,
                    array_map(static fn ($node): string => $node->id, $flow->nodes),
                ];
            },
            $result->all(),
        );
    }

    /**
     * @param list<string> $ids
     */
    private function changedSymbols(array $ids): ChangedSymbolResult
    {
        $changed = [];
        foreach ($ids as $id) {
            $name = str_contains($id, '::') ? substr($id, strrpos($id, '::') + 2) : $id;
            $changed[] = new ChangedSymbol(
                new Symbol(SymbolType::Method, $name, $id, 'src/Changed.php', 1, 10),
                [5],
            );
        }

        return new ChangedSymbolResult($changed, [], []);
    }

    /**
     * @param list<int> $lines
     */
    private function edge(
        string $source,
        string $target,
        DependencyType $type,
        array $lines = [10],
        int $occurrences = 1,
    ): GraphEdge {
        return new GraphEdge($source, $target, $type, $lines, $occurrences);
    }
}
