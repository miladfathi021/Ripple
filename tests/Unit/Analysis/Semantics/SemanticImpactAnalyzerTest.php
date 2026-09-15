<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Flow\AffectedFlow;
use Ripple\Analysis\Flow\AffectedFlowAnalyzer;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Flow\FlowNode;
use Ripple\Analysis\Flow\FlowType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\BlastRadiusOrigin;
use Ripple\Analysis\Impact\TransitiveImpactAnalyzer;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\SemanticImpactAnalyzer;
use Ripple\Analysis\Semantics\SemanticImpactResult;
use Ripple\Analysis\Semantics\StaticSemanticAnnotationProvider;

final class SemanticImpactAnalyzerTest extends TestCase
{
    public function testNoAnnotationsYieldEmptyResult(): void
    {
        $result = $this->analyze(
            [],
            $this->blastRadius(['ReservationController::update']),
            $this->flow('ReservationService::updateStatus', ['PaymentRepository::update']),
        );

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->blastRadiusAnnotations);
        $this->assertSame([], $result->affectedFlowAnnotations);
    }

    public function testAnnotationInBlastRadiusIsClassified(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('ReservationController::update', FlowSemanticType::ApiEntrypoint),
            ],
            $this->blastRadius(['ReservationController::update']),
            AffectedFlowResult::empty(),
        );

        $this->assertSame(
            [['ReservationController::update', 'api_entrypoint']],
            $this->snapshots($result->blastRadiusAnnotations),
        );
        $this->assertSame([], $this->snapshots($result->affectedFlowAnnotations));
    }

    public function testAnnotationInAffectedFlowIsClassified(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('PaymentRepository::update', FlowSemanticType::DatabaseWrite),
            ],
            TransitiveImpactResult::empty(),
            $this->flow('ReservationService::updateStatus', ['PaymentService::validate', 'PaymentRepository::update']),
        );

        $this->assertSame([], $this->snapshots($result->blastRadiusAnnotations));
        $this->assertSame(
            [['PaymentRepository::update', 'database_write']],
            $this->snapshots($result->affectedFlowAnnotations),
        );
    }

    public function testUnrelatedAnnotationIsIgnored(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('UnrelatedJob::handle', FlowSemanticType::Queue),
            ],
            $this->blastRadius(['ReservationController::update']),
            $this->flow('ReservationService::updateStatus', ['PaymentRepository::update']),
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testSameSymbolInBothContextsIsPreservedSeparately(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('Shared::run', FlowSemanticType::Event),
            ],
            $this->blastRadius(['Shared::run']),
            $this->flow('Changed::run', ['Shared::run']),
        );

        $this->assertSame(
            [['Shared::run', 'event']],
            $this->snapshots($result->blastRadiusAnnotations),
        );
        $this->assertSame(
            [['Shared::run', 'event']],
            $this->snapshots($result->affectedFlowAnnotations),
        );
    }

    public function testMultipleChangedSymbolsKeepIndependentMatches(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('ControllerA::update', FlowSemanticType::ApiEntrypoint),
                new SemanticAnnotation('RepoB::save', FlowSemanticType::DatabaseWrite),
            ],
            $this->blastRadius(['ControllerA::update', 'Other::run']),
            AffectedFlowResult::fromFlows([
                $this->singleFlow('ServiceA::run', ['RepoA::find']),
                $this->singleFlow('ServiceB::run', ['RepoB::save']),
            ]),
        );

        $this->assertSame(
            [['ControllerA::update', 'api_entrypoint']],
            $this->snapshots($result->blastRadiusAnnotations),
        );
        $this->assertSame(
            [['RepoB::save', 'database_write']],
            $this->snapshots($result->affectedFlowAnnotations),
        );
    }

    public function testMultipleSemanticTypesAreKeptAndOrdered(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('StripeClient::charge', FlowSemanticType::ExternalIntegration),
                new SemanticAnnotation('AuthService::check', FlowSemanticType::Authentication),
                new SemanticAnnotation('PaymentRepository::update', FlowSemanticType::DatabaseWrite),
                new SemanticAnnotation('ReservationController::update', FlowSemanticType::ApiEntrypoint),
            ],
            $this->blastRadius(['AuthService::check', 'ReservationController::update']),
            $this->flow('ReservationService::updateStatus', ['PaymentRepository::update', 'StripeClient::charge']),
        );

        $this->assertSame(
            [
                ['ReservationController::update', 'api_entrypoint'],
                ['AuthService::check', 'authentication'],
            ],
            $this->snapshots($result->blastRadiusAnnotations),
        );
        $this->assertSame(
            [
                ['PaymentRepository::update', 'database_write'],
                ['StripeClient::charge', 'external_integration'],
            ],
            $this->snapshots($result->affectedFlowAnnotations),
        );
    }

    public function testOrderingIsDeterministicAcrossRepeatedRuns(): void
    {
        $annotations = [
            new SemanticAnnotation('Z::run', FlowSemanticType::Queue),
            new SemanticAnnotation('A::run', FlowSemanticType::Event),
            new SemanticAnnotation('M::run', FlowSemanticType::Authentication),
        ];
        $blast = $this->blastRadius(['M::run', 'Z::run', 'A::run']);

        $first = $this->analyze($annotations, $blast, AffectedFlowResult::empty());
        $second = $this->analyze(array_reverse($annotations), $blast, AffectedFlowResult::empty());

        $this->assertSame(
            $this->snapshots($first->blastRadiusAnnotations),
            $this->snapshots($second->blastRadiusAnnotations),
        );
        $this->assertSame(
            [
                ['M::run', 'authentication'],
                ['A::run', 'event'],
                ['Z::run', 'queue'],
            ],
            $this->snapshots($first->blastRadiusAnnotations),
        );
    }

    public function testGraphDirectionIsPreservedForBlastRadiusAndFlows(): void
    {
        $graph = new DependencyGraph();
        foreach (['A', 'B', 'C'] as $id) {
            $graph->addNode(GraphNode::unindexed($id));
        }
        $graph->addEdge($this->edge('A', 'B', DependencyType::MethodCall));
        $graph->addEdge($this->edge('B', 'C', DependencyType::MethodCall));

        $changed = $this->changedSymbols(['B']);
        $blastRadius = (new TransitiveImpactAnalyzer())->analyze(
            $changed,
            (new ReverseDependencyGraphBuilder())->build($graph),
        );
        $affectedFlows = (new AffectedFlowAnalyzer())->analyze($changed, $graph);

        $this->assertSame(['A'], $blastRadius->getImpactedSymbolIds());
        $this->assertSame(['C'], array_map(
            static fn ($node): string => $node->id,
            $affectedFlows->all()[0]->nodes,
        ));

        $result = $this->analyze(
            [
                new SemanticAnnotation('A', FlowSemanticType::ApiEntrypoint),
                new SemanticAnnotation('C', FlowSemanticType::DatabaseWrite),
            ],
            $blastRadius,
            $affectedFlows,
        );

        $this->assertSame([['A', 'api_entrypoint']], $this->snapshots($result->blastRadiusAnnotations));
        $this->assertSame([['C', 'database_write']], $this->snapshots($result->affectedFlowAnnotations));
        $this->assertSame([], array_filter(
            $this->snapshots($result->affectedFlowAnnotations),
            static fn (array $row): bool => $row[0] === 'A',
        ));
        $this->assertSame([], array_filter(
            $this->snapshots($result->blastRadiusAnnotations),
            static fn (array $row): bool => $row[0] === 'C',
        ));
    }

    public function testUnknownAnnotatedSymbolDoesNotNeedToExistOnTheGraph(): void
    {
        $result = $this->analyze(
            [
                new SemanticAnnotation('Future\\Adapter::handle', FlowSemanticType::Queue),
                new SemanticAnnotation('PaymentRepository::update', FlowSemanticType::DatabaseWrite),
            ],
            TransitiveImpactResult::empty(),
            $this->flow('Service::run', ['PaymentRepository::update']),
        );

        $this->assertSame(
            [['PaymentRepository::update', 'database_write']],
            $this->snapshots($result->affectedFlowAnnotations),
        );
    }

    /**
     * @param list<SemanticAnnotation> $annotations
     */
    private function analyze(
        array $annotations,
        TransitiveImpactResult $blastRadius,
        AffectedFlowResult $affectedFlows,
    ): SemanticImpactResult {
        return (new SemanticImpactAnalyzer(new StaticSemanticAnnotationProvider($annotations)))
            ->analyze($blastRadius, $affectedFlows);
    }

    /**
     * @param list<string> $ids
     */
    private function blastRadius(array $ids): TransitiveImpactResult
    {
        $entries = [];
        foreach ($ids as $id) {
            $entries[] = new BlastRadiusEntry(
                $id,
                1,
                [new BlastRadiusOrigin('Changed::run', 1, $this->edge($id, 'Changed::run', DependencyType::MethodCall))],
            );
        }

        return TransitiveImpactResult::fromEntries($entries);
    }

    /**
     * @param list<string> $nodeIds
     */
    private function flow(string $origin, array $nodeIds): AffectedFlowResult
    {
        return AffectedFlowResult::fromFlows([$this->singleFlow($origin, $nodeIds)]);
    }

    /**
     * @param list<string> $nodeIds
     */
    private function singleFlow(string $origin, array $nodeIds): AffectedFlow
    {
        $nodes = [];
        $edges = [];
        $previous = $origin;
        foreach ($nodeIds as $index => $id) {
            $depth = $index + 1;
            $nodes[] = new FlowNode($id, $depth, true);
            $edges[] = $this->edge($previous, $id, DependencyType::MethodCall);
            $previous = $id;
        }

        return new AffectedFlow($origin, FlowType::CallChain, $nodes, $edges, count($nodeIds));
    }

    /**
     * @param list<string> $ids
     */
    private function changedSymbols(array $ids): ChangedSymbolResult
    {
        $changed = [];
        foreach ($ids as $id) {
            $changed[] = new ChangedSymbol(
                new Symbol(SymbolType::Method, $id, $id, 'src/Changed.php', 1, 10),
                [5],
            );
        }

        return new ChangedSymbolResult($changed, [], []);
    }

    private function edge(string $source, string $target, DependencyType $type): GraphEdge
    {
        return new GraphEdge($source, $target, $type, [10], 1);
    }

    /**
     * @param list<SemanticAnnotation> $annotations
     * @return list<array{string, string}>
     */
    private function snapshots(array $annotations): array
    {
        return array_map(
            static fn (SemanticAnnotation $annotation): array => [$annotation->symbolId, $annotation->type->value],
            $annotations,
        );
    }
}
