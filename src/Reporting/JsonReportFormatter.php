<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\ChangedSymbols\UnmappedFileLines;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Flow\AffectedFlow;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Flow\FlowNode;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\GraphNode;
use Ripple\Analysis\Graph\ReverseDependencyGraph;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\BlastRadiusOrigin;
use Ripple\Analysis\Impact\DirectImpact;
use Ripple\Analysis\Impact\DirectImpactResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Index\RepositoryIndex;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreContribution;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\SemanticImpactResult;
use Ripple\Analysis\Tests\TestImpact;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Git\ChangedFile;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;

final class JsonReportFormatter implements ReportFormatter
{
    public function format(
        AnalysisResult $result,
        ?AIPrExplanation $explanation = null,
        ?AIRiskExplanation $riskExplanation = null,
    ): string
    {
        $payload = [
            'status' => $result->status,
        ];

        if (!$result->isSuccessful()) {
            $payload['message'] = $result->message;
        } else {
            $changedSymbols = $result->changedSymbols ?? ChangedSymbolResult::empty();
            $payload['diff'] = [
                'files' => array_map(
                    static fn (ChangedFile $file): array => [
                        'path' => $file->path,
                        'change_type' => $file->changeType->value,
                        'added_lines' => $file->addedLines,
                        'deleted_lines' => $file->deletedLines,
                    ],
                    $result->diff?->files ?? [],
                ),
            ];
            $payload['changed_symbols'] = array_map(
                $this->changedSymbolPayload(...),
                $changedSymbols->changedSymbols,
            );
            $payload['direct_impact'] = array_map(
                $this->directImpactPayload(...),
                ($result->directImpact ?? DirectImpactResult::empty())->impacts,
            );
            $payload['blast_radius'] = array_map(
                $this->blastRadiusPayload(...),
                ($result->blastRadius ?? TransitiveImpactResult::empty())->entries,
            );
            $payload['test_impact'] = array_map(
                $this->testImpactPayload(...),
                ($result->testImpact ?? TestImpactResult::empty())->impacts,
            );
            $payload['risk_factors'] = array_map(
                $this->riskFactorPayload(...),
                ($result->riskFactors ?? RiskFactorResult::empty())->all(),
            );
            $payload['risk_score'] = $this->riskScorePayload(
                $result->riskScore ?? RiskScoreResult::none(),
            );
            $payload['affected_flows'] = array_map(
                $this->affectedFlowPayload(...),
                ($result->affectedFlows ?? AffectedFlowResult::empty())->all(),
            );
            $payload['semantic_impact'] = $this->semanticImpactPayload(
                $result->semanticImpact ?? SemanticImpactResult::empty(),
            );
            $payload['churn'] = $this->churnPayload(
                $result->churn ?? ChurnResult::empty(),
            );
            $payload['unmapped_lines'] = array_map(
                $this->unmappedPayload(...),
                $changedSymbols->unmappedLines,
            );
            $payload['unmapped_deleted_lines'] = array_map(
                $this->unmappedPayload(...),
                $changedSymbols->unmappedDeletedLines,
            );
            $payload['dependencies'] = array_map(
                $this->dependencyPayload(...),
                ($result->dependencies ?? DependencyResult::empty())->dependencies,
            );
            $payload['graph'] = $this->graphPayload($result->graph ?? new DependencyGraph());
            $payload['reverse_graph'] = $this->reverseGraphPayload(
                $result->reverseGraph ?? new ReverseDependencyGraph([], []),
            );
            $payload['project_index'] = $this->projectIndexPayload(
                $result->repositoryIndex ?? RepositoryIndex::empty(),
            );
        }

        if ($explanation !== null && $explanation->wasGenerated()) {
            $payload['ai_explanation'] = [
                'generated' => true,
                'text' => $explanation->text,
            ];
        }

        if ($riskExplanation !== null && $riskExplanation->wasGenerated()) {
            $payload['ai_risk_explanation'] = [
                'generated' => true,
                'text' => $riskExplanation->text,
            ];
        }

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function changedSymbolPayload(ChangedSymbol $changedSymbol): array
    {
        $symbol = $changedSymbol->symbol;

        return [
            'type' => $symbol->type->value,
            'name' => $symbol->name,
            'fully_qualified_name' => $symbol->fullyQualifiedName,
            'file' => $symbol->file,
            'start_line' => $symbol->startLine,
            'end_line' => $symbol->endLine,
            'parent' => $symbol->parent,
            'visibility' => $symbol->visibility,
            'is_static' => $symbol->isStatic,
            'changed_lines' => $changedSymbol->changedLines,
        ];
    }

    /**
     * @return array{changed_symbol: string, impacted_symbol: string, dependency_type: string, lines: list<int>, occurrences: int}
     */
    private function directImpactPayload(DirectImpact $impact): array
    {
        return [
            'changed_symbol' => $impact->changedSymbolId,
            'impacted_symbol' => $impact->impactedSymbolId,
            'dependency_type' => $impact->edge->type->value,
            'lines' => $impact->edge->lines,
            'occurrences' => $impact->edge->occurrences,
        ];
    }

    /**
     * @return array{impacted_symbol: string, depth: int, origins: list<array{changed_symbol: string, depth: int, dependency_type: string, lines: list<int>, occurrences: int}>}
     */
    private function blastRadiusPayload(BlastRadiusEntry $entry): array
    {
        return [
            'impacted_symbol' => $entry->impactedSymbolId,
            'depth' => $entry->depth,
            'origins' => array_map($this->blastRadiusOriginPayload(...), $entry->origins),
        ];
    }

    /**
     * @return array{changed_symbol: string, test_symbol: string, test_file: string, depth: int, impact: string}
     */
    private function testImpactPayload(TestImpact $impact): array
    {
        return [
            'changed_symbol' => $impact->changedSymbolId,
            'test_symbol' => $impact->test->id,
            'test_file' => $impact->test->file,
            'depth' => $impact->depth,
            'impact' => $impact->impact->value,
        ];
    }

    /**
     * @return array{changed_symbol: string, depth: int, dependency_type: string, lines: list<int>, occurrences: int}
     */
    private function blastRadiusOriginPayload(BlastRadiusOrigin $origin): array
    {
        return [
            'changed_symbol' => $origin->changedSymbolId,
            'depth' => $origin->depth,
            'dependency_type' => $origin->edge->type->value,
            'lines' => $origin->edge->lines,
            'occurrences' => $origin->edge->occurrences,
        ];
    }

    /**
     * @return array{code: string, severity: string, title: string, description: string, value: int, evidence: array<string, mixed>}
     */
    private function riskFactorPayload(RiskFactor $factor): array
    {
        return [
            'code' => $factor->code,
            'severity' => $factor->severity->value,
            'title' => $factor->title,
            'description' => $factor->description,
            'value' => $factor->value,
            'evidence' => $factor->evidence,
        ];
    }

    /**
     * @return array{score: int, level: string, contributions: list<array{code: string, title: string, severity: string, max_weight: int, contribution: int}>}
     */
    private function riskScorePayload(RiskScoreResult $riskScore): array
    {
        return [
            'score' => $riskScore->score()->value(),
            'level' => $riskScore->level()->value,
            'contributions' => array_map($this->riskScoreContributionPayload(...), $riskScore->contributions()),
        ];
    }

    /**
     * @return array{code: string, title: string, severity: string, max_weight: int, contribution: int}
     */
    private function riskScoreContributionPayload(RiskScoreContribution $contribution): array
    {
        return [
            'code' => $contribution->code,
            'title' => $contribution->title,
            'severity' => $contribution->severity->value,
            'max_weight' => $contribution->maxWeight,
            'contribution' => $contribution->contribution,
        ];
    }

    /**
     * @return array{changed_symbol: string, type: string, depth: int, nodes: list<array{id: string, depth: int}>, edges: list<array{source: string, target: string, dependency_type: string, lines: list<int>, occurrences: int}>}
     */
    private function affectedFlowPayload(AffectedFlow $flow): array
    {
        return [
            'changed_symbol' => $flow->changedSymbolId,
            'type' => $flow->type->value,
            'depth' => $flow->depth,
            'nodes' => array_map($this->flowNodePayload(...), $flow->nodes),
            'edges' => array_map($this->flowEdgePayload(...), $flow->edges),
        ];
    }

    /**
     * @return array{id: string, depth: int}
     */
    private function flowNodePayload(FlowNode $node): array
    {
        return [
            'id' => $node->id,
            'depth' => $node->depth,
        ];
    }

    /**
     * @return array{source: string, target: string, dependency_type: string, lines: list<int>, occurrences: int}
     */
    private function flowEdgePayload(GraphEdge $edge): array
    {
        return [
            'source' => $edge->source,
            'target' => $edge->target,
            'dependency_type' => $edge->type->value,
            'lines' => $edge->lines,
            'occurrences' => $edge->occurrences,
        ];
    }

    /**
     * @return array{blast_radius: list<array{symbol: string, type: string}>, affected_flows: list<array{symbol: string, type: string}>}
     */
    private function semanticImpactPayload(SemanticImpactResult $semanticImpact): array
    {
        return [
            'blast_radius' => array_map($this->semanticAnnotationPayload(...), $semanticImpact->blastRadiusAnnotations),
            'affected_flows' => array_map($this->semanticAnnotationPayload(...), $semanticImpact->affectedFlowAnnotations),
        ];
    }

    /**
     * @return array{symbol: string, type: string}
     */
    private function semanticAnnotationPayload(SemanticAnnotation $annotation): array
    {
        return [
            'symbol' => $annotation->symbolId,
            'type' => $annotation->type->value,
        ];
    }

    /**
     * @return list<array{file: string, commit_count: int, lines_added: int, lines_deleted: int, contributors_count: int, last_changed_at: ?string}>
     */
    private function churnPayload(ChurnResult $churn): array
    {
        return array_map(
            static function (FileChurn $file): array {
                return [
                    'file' => $file->file,
                    'commit_count' => $file->commitCount,
                    'lines_added' => $file->linesAdded,
                    'lines_deleted' => $file->linesDeleted,
                    'contributors_count' => $file->contributorsCount,
                    'last_changed_at' => $file->lastChangedAt?->format(DATE_ATOM),
                ];
            },
            $churn->files,
        );
    }

    /**
     * @return array{file: string, lines: list<int>}
     */
    private function unmappedPayload(UnmappedFileLines $unmapped): array
    {
        return [
            'file' => $unmapped->file,
            'lines' => $unmapped->lines,
        ];
    }

    /**
     * @return array{source: string, target: string, type: string, line: int, lines: list<int>, occurrences: int}
     */
    private function dependencyPayload(Dependency $dependency): array
    {
        return [
            'source' => $dependency->source,
            'target' => $dependency->target,
            'type' => $dependency->type->value,
            'line' => $dependency->line(),
            'lines' => $dependency->lines,
            'occurrences' => $dependency->occurrences,
        ];
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function graphPayload(DependencyGraph $graph): array
    {
        return [
            'nodes' => array_map($this->graphNodePayload(...), $graph->getNodes()),
            'edges' => array_map($this->graphEdgePayload(...), $graph->getEdges()),
        ];
    }

    /**
     * @return array{id: string, type: ?string, name: string, fully_qualified_name: string, file: ?string, known: bool}
     */
    private function graphNodePayload(GraphNode $node): array
    {
        return [
            'id' => $node->id,
            'type' => $node->type,
            'name' => $node->name,
            'fully_qualified_name' => $node->fullyQualifiedName,
            'file' => $node->file,
            'known' => $node->known,
        ];
    }

    /**
     * @return array{source: string, target: string, type: string, lines: list<int>, occurrences: int}
     */
    private function graphEdgePayload(GraphEdge $edge): array
    {
        return [
            'source' => $edge->source,
            'target' => $edge->target,
            'type' => $edge->type->value,
            'lines' => $edge->lines,
            'occurrences' => $edge->occurrences,
        ];
    }

    /**
     * @return array{dependents: \stdClass|array<string, list<array<string, mixed>>>}
     */
    private function reverseGraphPayload(ReverseDependencyGraph $reverseGraph): array
    {
        $dependents = [];
        foreach ($reverseGraph->getNodes() as $node) {
            $edges = $reverseGraph->getDependentEdges($node->id);
            if ($edges === []) {
                continue;
            }

            $dependents[$node->id] = array_map($this->graphEdgePayload(...), $edges);
        }

        return [
            'dependents' => $dependents === [] ? new \stdClass() : $dependents,
        ];
    }

    /**
     * @return array{php_files: int, symbols: int, dependencies: int}
     */
    private function projectIndexPayload(RepositoryIndex $index): array
    {
        return [
            'php_files' => $index->phpFileCount(),
            'symbols' => $index->symbolCount(),
            'dependencies' => $index->dependencyCount(),
        ];
    }
}
