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
use Ripple\Analysis\Impact\BlastRadiusOrigin;
use Ripple\Analysis\Impact\TransitiveImpactAnalyzer;
use Ripple\Analysis\Impact\TransitiveImpactResult;

final class TransitiveImpactAnalyzerTest extends TestCase
{
    public function testOneLevelImpactHasDepthOne(): void
    {
        $result = $this->analyze(
            ['A'],
            [$this->edge('B', 'A', DependencyType::MethodCall)],
        );

        $this->assertSame(['B'], $result->getImpactedSymbolIds());
        $this->assertSame(1, $result->getEntry('B')?->depth);
        $this->assertSame('A', $result->getEntry('B')?->origins[0]->changedSymbolId);
        $this->assertSame(1, $result->getEntry('B')?->origins[0]->depth);
    }

    public function testMultiLevelChainRecordsMinimumDepths(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'B', DependencyType::MethodCall),
                $this->edge('D', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B', 'C', 'D'], $result->getImpactedSymbolIds());
        $this->assertSame(1, $result->getEntry('B')?->depth);
        $this->assertSame(2, $result->getEntry('C')?->depth);
        $this->assertSame(3, $result->getEntry('D')?->depth);
        $this->assertNotContains('A', $result->getImpactedSymbolIds());
    }

    public function testTransitiveDependentIsNotDepthOne(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'B', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(1, $result->getEntry('B')?->depth);
        $this->assertSame(2, $result->getEntry('C')?->depth);
        $this->assertCount(1, $result->getEntriesAtDepth(1));
        $this->assertSame(['B'], array_map(
            static fn ($entry): string => $entry->impactedSymbolId,
            $result->getEntriesAtDepth(1),
        ));
    }

    public function testMultipleBranchesShareDepthOne(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B', 'C'], $result->getImpactedSymbolIds());
        $this->assertSame(1, $result->getEntry('B')?->depth);
        $this->assertSame(1, $result->getEntry('C')?->depth);
    }

    public function testBranchedMultiLevelGraph(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::MethodCall),
                $this->edge('D', 'B', DependencyType::MethodCall),
                $this->edge('E', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B', 'C', 'D', 'E'], $result->getImpactedSymbolIds());
        $this->assertSame(1, $result->getEntry('B')?->depth);
        $this->assertSame(1, $result->getEntry('C')?->depth);
        $this->assertSame(2, $result->getEntry('D')?->depth);
        $this->assertSame(2, $result->getEntry('E')?->depth);
    }

    public function testCycleTerminatesAndExcludesChangedSymbol(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'B', DependencyType::MethodCall),
                $this->edge('A', 'C', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B', 'C'], $result->getImpactedSymbolIds());
        $this->assertSame(1, $result->getEntry('B')?->depth);
        $this->assertSame(2, $result->getEntry('C')?->depth);
        $this->assertNull($result->getEntry('A'));
    }

    public function testTwoNodeCycleExcludesChangedSymbol(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('A', 'B', DependencyType::MethodCall),
            ],
        );

        $this->assertSame(['B'], $result->getImpactedSymbolIds());
        $this->assertSame(1, $result->getEntry('B')?->depth);
    }

    public function testMultipleChangedSymbolsDeduplicateSharedDependent(): void
    {
        $result = $this->analyze(
            ['A', 'X'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'B', DependencyType::MethodCall),
                $this->edge('Y', 'X', DependencyType::MethodCall),
                $this->edge('C', 'Y', DependencyType::StaticCall),
            ],
        );

        $this->assertSame(['B', 'Y', 'C'], $result->getImpactedSymbolIds());
        $this->assertCount(1, array_filter(
            $result->getImpactedSymbolIds(),
            static fn (string $id): bool => $id === 'C',
        ));

        $origins = array_map(
            static fn (BlastRadiusOrigin $origin): string => $origin->changedSymbolId,
            $result->getEntry('C')?->origins ?? [],
        );
        $this->assertSame(['A', 'X'], $origins);
        $this->assertSame(2, $result->getEntry('C')?->depth);
    }

    public function testSharedTransitiveDependentAppearsOnceWithBothEdges(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall, [1]),
                $this->edge('C', 'A', DependencyType::MethodCall, [2]),
                $this->edge('D', 'B', DependencyType::MethodCall, [3]),
                $this->edge('D', 'C', DependencyType::StaticCall, [4]),
            ],
        );

        $this->assertSame(['B', 'C', 'D'], $result->getImpactedSymbolIds());
        $this->assertSame(2, $result->getEntry('D')?->depth);
        $this->assertCount(2, $result->getEntry('D')?->origins ?? []);
        $this->assertSame('B', $result->getEntry('D')?->origins[0]->edge->target);
        $this->assertSame('C', $result->getEntry('D')?->origins[1]->edge->target);
        $this->assertSame(DependencyType::MethodCall, $result->getEntry('D')?->origins[0]->edge->type);
        $this->assertSame(DependencyType::StaticCall, $result->getEntry('D')?->origins[1]->edge->type);
    }

    public function testDifferentDepthsFromMultipleOriginsArePreserved(): void
    {
        $result = $this->analyze(
            ['A', 'X'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'B', DependencyType::MethodCall, [20]),
                $this->edge('C', 'X', DependencyType::MethodCall, [5]),
            ],
        );

        $entry = $result->getEntry('C');
        $this->assertNotNull($entry);
        $this->assertSame(1, $entry->depth);
        $this->assertCount(2, $entry->origins);
        $this->assertSame('A', $entry->origins[0]->changedSymbolId);
        $this->assertSame(2, $entry->origins[0]->depth);
        $this->assertSame('X', $entry->origins[1]->changedSymbolId);
        $this->assertSame(1, $entry->origins[1]->depth);
    }

    public function testChangedSymbolWithNoDependentsYieldsEmptyBlastRadius(): void
    {
        $result = $this->analyze(['A'], []);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->count());
        $this->assertSame([], $result->getImpactedSymbolIds());
    }

    public function testSelfEdgeIsNotReportedAsImpact(): void
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

    public function testPreservesDependencyMetadata(): void
    {
        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall, [42, 43], 2),
                $this->edge('B', 'A', DependencyType::ParameterType, [12], 1),
            ],
        );

        $this->assertSame(['B'], $result->getImpactedSymbolIds());
        $this->assertCount(2, $result->getEntry('B')?->origins ?? []);

        $methodCall = $result->getEntry('B')?->origins[0];
        $this->assertSame(DependencyType::MethodCall, $methodCall?->edge->type);
        $this->assertSame([42, 43], $methodCall?->edge->lines);
        $this->assertSame(2, $methodCall?->edge->occurrences);
        $this->assertSame('B', $methodCall?->edge->source);
        $this->assertSame('A', $methodCall?->edge->target);

        $parameterType = $result->getEntry('B')?->origins[1];
        $this->assertSame(DependencyType::ParameterType, $parameterType?->edge->type);
        $this->assertSame([12], $parameterType?->edge->lines);
        $this->assertSame(1, $parameterType?->edge->occurrences);
    }

    public function testResultOrderIsDeterministic(): void
    {
        $edges = [
            $this->edge('Z', 'X', DependencyType::StaticCall),
            $this->edge('A', 'X', DependencyType::MethodCall),
            $this->edge('M', 'X', DependencyType::ConstructorCall),
            $this->edge('D', 'A', DependencyType::MethodCall),
            $this->edge('C', 'M', DependencyType::MethodCall),
        ];

        $first = $this->analyze(['X'], $edges);
        $second = $this->analyze(['X'], array_reverse($edges));

        $this->assertSame(['A', 'M', 'Z', 'C', 'D'], $first->getImpactedSymbolIds());
        $this->assertSame($first->getImpactedSymbolIds(), $second->getImpactedSymbolIds());
        $this->assertSame($this->originSnapshot($first), $this->originSnapshot($second));
        $this->assertSame(1, $first->getEntry('A')?->depth);
        $this->assertSame(1, $first->getEntry('M')?->depth);
        $this->assertSame(1, $first->getEntry('Z')?->depth);
        $this->assertSame(2, $first->getEntry('C')?->depth);
        $this->assertSame(2, $first->getEntry('D')?->depth);
    }

    public function testIsolatedIndexedSymbolIsNotInBlastRadius(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode(GraphNode::unindexed('ReservationService::updateStatus'));
        $graph->addNode(GraphNode::unindexed('PaymentService::validate'));
        $graph->addNode(GraphNode::unindexed('AuditService::record'));
        $graph->addNode(GraphNode::unindexed('PaymentRepository::update'));
        $graph->addNode(GraphNode::unindexed('NotificationService::send'));
        $graph->addNode(GraphNode::unindexed('UnusedService'));
        $graph->addEdge($this->edge('PaymentService::validate', 'ReservationService::updateStatus', DependencyType::MethodCall, [6]));
        $graph->addEdge($this->edge('AuditService::record', 'ReservationService::updateStatus', DependencyType::MethodCall, [6]));
        $graph->addEdge($this->edge('PaymentRepository::update', 'PaymentService::validate', DependencyType::MethodCall, [12]));
        $graph->addEdge($this->edge('NotificationService::send', 'PaymentService::validate', DependencyType::MethodCall, [8]));

        $result = (new TransitiveImpactAnalyzer())->analyze(
            new ChangedSymbolResult([$this->changed('ReservationService::updateStatus')], [], []),
            (new ReverseDependencyGraphBuilder())->build($graph),
        );

        $this->assertSame(
            [
                'AuditService::record',
                'PaymentService::validate',
                'NotificationService::send',
                'PaymentRepository::update',
            ],
            $result->getImpactedSymbolIds(),
        );
        $this->assertSame(1, $result->getEntry('AuditService::record')?->depth);
        $this->assertSame(1, $result->getEntry('PaymentService::validate')?->depth);
        $this->assertSame(2, $result->getEntry('NotificationService::send')?->depth);
        $this->assertSame(2, $result->getEntry('PaymentRepository::update')?->depth);
        $this->assertNull($result->getEntry('UnusedService'));
        $this->assertNull($result->getEntry('ReservationService::updateStatus'));
    }

    public function testDoesNotScanUnreachableSymbols(): void
    {
        $unrelated = $this->edge('FarAway::run', 'Unrelated::target', DependencyType::MethodCall);

        $result = $this->analyze(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $unrelated,
            ],
        );

        $this->assertSame(['B'], $result->getImpactedSymbolIds());
        $this->assertNull($result->getEntry('FarAway::run'));
        $this->assertNull($result->getEntry('Unrelated::target'));
    }

    /**
     * @param list<string> $changedIds
     * @param list<GraphEdge> $edges
     */
    private function analyze(array $changedIds, array $edges): TransitiveImpactResult
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

        return (new TransitiveImpactAnalyzer())->analyze(
            new ChangedSymbolResult($changed, [], []),
            (new ReverseDependencyGraphBuilder())->build($graph),
        );
    }

    /**
     * @return list<array{string, int, string, string, string, list<int>, int}>
     */
    private function originSnapshot(TransitiveImpactResult $result): array
    {
        $snapshot = [];
        foreach ($result->entries as $entry) {
            foreach ($entry->origins as $origin) {
                $snapshot[] = [
                    $entry->impactedSymbolId,
                    $entry->depth,
                    $origin->changedSymbolId,
                    $origin->depth,
                    $origin->edge->type->value,
                    $origin->edge->source,
                    $origin->edge->target,
                    $origin->edge->lines,
                    $origin->edge->occurrences,
                ];
            }
        }

        return $snapshot;
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
