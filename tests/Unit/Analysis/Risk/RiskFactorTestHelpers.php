<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;
use Ripple\Analysis\Graph\ReverseDependencyGraph;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Git\History\ChurnResult;

trait RiskFactorTestHelpers
{
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
     * @param array<string, int> $idToDepth
     */
    private function blastRadius(array $idToDepth): TransitiveImpactResult
    {
        $entries = [];
        foreach ($idToDepth as $id => $depth) {
            $entries[] = new BlastRadiusEntry($id, $depth, []);
        }

        return TransitiveImpactResult::fromEntries($entries);
    }

    /**
     * @param list<string> $changedIds
     * @param list<GraphEdge> $edges
     * @param array<string, int> $idToDepth
     */
    private function context(
        array $changedIds = [],
        array $edges = [],
        array $idToDepth = [],
        ?ChurnResult $churn = null,
    ): RiskFactorContext {
        return new RiskFactorContext(
            $this->changedSymbols($changedIds),
            $this->reverseGraph($edges),
            $this->blastRadius($idToDepth),
            $churn ?? ChurnResult::empty(),
        );
    }

    /**
     * @param list<int> $lines
     */
    private function edge(
        string $source,
        string $target,
        DependencyType $type = DependencyType::MethodCall,
        array $lines = [10],
        int $occurrences = 1,
    ): GraphEdge {
        return new GraphEdge($source, $target, $type, $lines, $occurrences);
    }
}
