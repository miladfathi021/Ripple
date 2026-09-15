<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Impact;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;
use Ripple\Analysis\Impact\DirectImpactAnalyzer;
use Ripple\Analysis\Impact\DirectImpactResult;

final class DirectImpactAnalyzerTest extends TestCase
{
    public function testOneDirectDependent(): void
    {
        $result = $this->analyze(
            ['A'],
            [$this->edge('B', 'A', DependencyType::MethodCall)],
        );

        $this->assertSame(['B'], $this->impactedIds($result));
        $this->assertSame('A', $result->impacts[0]->changedSymbolId);
        $this->assertSame('B', $result->impacts[0]->impactedSymbolId);
        $this->assertSame('B', $result->impacts[0]->edge->source);
        $this->assertSame('A', $result->impacts[0]->edge->target);
        $this->assertSame(DependencyType::MethodCall, $result->impacts[0]->edge->type);
        $this->assertSame([10], $result->impacts[0]->edge->lines);
        $this->assertSame(1, $result->impacts[0]->edge->occurrences);
    }

    public function testExcludesTransitiveDependents(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'B', DependencyType::MethodCall),
                $this->edge('D', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B'], $this->impactedIds($result));
        $this->assertSame(1, $result->count());
    }

    public function testMultipleDirectDependents(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('D', 'A', DependencyType::MethodCall),
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B', 'C', 'D'], $this->impactedIds($result));
    }

    public function testMultipleChangedSymbols(): void
    {
        $result = $this->analyze(
            ['A', 'C'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('D', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B', 'D'], $this->impactedIds($result));
        $this->assertSame(['B'], $this->impactedIdsFor($result, 'A'));
        $this->assertSame(['D'], $this->impactedIdsFor($result, 'C'));
    }

    public function testSharedImpactedSymbolIsDeduplicatedWithBothReasons(): void
    {
        $result = $this->analyze(
            ['A', 'C'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall, [1]),
                $this->edge('B', 'C', DependencyType::StaticCall, [2]),
            ],
        );

        $this->assertSame(['B'], $this->impactedIds($result));
        $this->assertCount(1, $result->getImpactedSymbols());
        $this->assertSame(['A', 'C'], $result->getImpactedSymbols()[0]->causedBy);
        $this->assertCount(2, $result->impacts);
        $this->assertCount(2, $result->getImpactedSymbols()[0]->edges);
    }

    public function testChangedSymbolWithNoDependentsYieldsEmptyImpact(): void
    {
        $result = $this->analyze(['A'], []);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->count());
        $this->assertSame([], $result->getImpactedSymbols());
        $this->assertSame([], $result->getImpactsForChangedSymbol('A'));
    }

    public function testImpactFollowsDependentsNotDependencies(): void
    {
        $edges = [$this->edge('A', 'B', DependencyType::MethodCall)];

        $whenAChanges = $this->analyze(['A'], $edges);
        $whenBChanges = $this->analyze(['B'], $edges);

        $this->assertTrue($whenAChanges->isEmpty());
        $this->assertSame(['A'], $this->impactedIds($whenBChanges));
        $this->assertSame('A', $whenBChanges->impacts[0]->edge->source);
        $this->assertSame('B', $whenBChanges->impacts[0]->edge->target);
    }

    public function testPreservesDistinctDependencyTypes(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall, [35]),
                $this->edge('B', 'A', DependencyType::ParameterType, [12]),
            ],
        );

        $this->assertSame(['B'], $this->impactedIds($result));
        $this->assertCount(2, $result->impacts);
        $this->assertSame('method_call', $result->impacts[0]->edge->type->value);
        $this->assertSame('parameter_type', $result->impacts[1]->edge->type->value);
        $this->assertSame([35], $result->impacts[0]->edge->lines);
        $this->assertSame([12], $result->impacts[1]->edge->lines);
    }

    public function testSelfDependencyIsNotReportedAsImpact(): void
    {
        $result = $this->analyze(
            ['A'],
            [$this->edge('A', 'A', DependencyType::MethodCall)],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testUnknownChangedSymbolDoesNotInventImpact(): void
    {
        $result = $this->analyze(
            ['Missing'],
            [$this->edge('B', 'A', DependencyType::MethodCall)],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testResultOrderIsDeterministic(): void
    {
        $first = $this->analyze(
            ['X'],
            [
                $this->edge('Z', 'X', DependencyType::StaticCall),
                $this->edge('A', 'X', DependencyType::MethodCall),
                $this->edge('M', 'X', DependencyType::ConstructorCall),
            ],
        );
        $second = $this->analyze(
            ['X'],
            [
                $this->edge('M', 'X', DependencyType::ConstructorCall),
                $this->edge('A', 'X', DependencyType::MethodCall),
                $this->edge('Z', 'X', DependencyType::StaticCall),
            ],
        );

        $this->assertSame(['A', 'M', 'Z'], $this->impactedIds($first));
        $this->assertSame($this->impactedIds($first), $this->impactedIds($second));
        $this->assertSame(
            array_map(
                static fn ($impact): array => [$impact->impactedSymbolId, $impact->changedSymbolId, $impact->edge->type->value],
                $first->impacts,
            ),
            array_map(
                static fn ($impact): array => [$impact->impactedSymbolId, $impact->changedSymbolId, $impact->edge->type->value],
                $second->impacts,
            ),
        );
    }

    /**
     * @param list<string> $changedIds
     * @param list<GraphEdge> $edges
     */
    private function analyze(array $changedIds, array $edges): DirectImpactResult
    {
        $graph = new DependencyGraph();
        foreach ($edges as $edge) {
            $graph->addNode(GraphNode::unindexed($edge->source));
            $graph->addNode(GraphNode::unindexed($edge->target));
            $graph->addEdge($edge);
        }

        $changed = [];
        foreach ($changedIds as $id) {
            $changed[] = $this->changed($id);
        }

        return (new DirectImpactAnalyzer())->analyze(
            new ChangedSymbolResult($changed, [], []),
            (new ReverseDependencyGraphBuilder())->build($graph),
        );
    }

    /**
     * @return list<string>
     */
    private function impactedIds(DirectImpactResult $result): array
    {
        return array_map(
            static fn ($symbol): string => $symbol->id,
            $result->getImpactedSymbols(),
        );
    }

    /**
     * @return list<string>
     */
    private function impactedIdsFor(DirectImpactResult $result, string $changedId): array
    {
        return array_map(
            static fn ($impact): string => $impact->impactedSymbolId,
            $result->getImpactsForChangedSymbol($changedId),
        );
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

    private function changed(string $fqn): ChangedSymbol
    {
        $name = str_contains($fqn, '::') ? substr($fqn, strrpos($fqn, '::') + 2) : $fqn;

        return new ChangedSymbol(
            new Symbol(SymbolType::Method, $name, $fqn, 'src/Changed.php', 1, 10),
            [5],
        );
    }
}
