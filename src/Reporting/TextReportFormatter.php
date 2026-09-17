<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\AI\Testing\AITestRecommendation;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\ReverseDependencyGraph;
use Ripple\Analysis\Impact\DirectImpactResult;
use Ripple\Analysis\Impact\ImpactedSymbol;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Index\RepositoryIndex;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\SemanticImpactResult;
use Ripple\Analysis\Tests\TestImpact;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;
use DateTimeImmutable;
use DateTimeZone;

final class TextReportFormatter implements ReportFormatter
{
    public function format(
        AnalysisResult $result,
        ?AIPrExplanation $explanation = null,
        ?AIRiskExplanation $riskExplanation = null,
        ?AITestRecommendation $testRecommendation = null,
    ): string
    {
        if (!$result->isSuccessful()) {
            return $result->message;
        }

        $files = $result->diff?->files ?? [];
        $lines = [
            '🌊 Ripple',
        ];
        $lines = array_merge($lines, $this->repositoryIndexSection($result->repositoryIndex));
        $lines[] = '';
        $lines[] = 'Changed files: ' . count($files);

        foreach ($files as $file) {
            $lines[] = '';
            $lines[] = $this->fileHeading($file);
            $counts = $this->countSummary($file);
            if ($counts !== null) {
                $lines[] = $counts;
            }
        }

        return implode("\n", array_merge(
            $lines,
            $this->changedSymbolSection($result->changedSymbols),
            $this->directImpactSection($result->directImpact),
            $this->blastRadiusSection($result->blastRadius),
            $this->testImpactSection($result->testImpact),
            $this->riskScoreSection($result->riskScore),
            $this->riskFactorSection($result->riskFactors),
            $this->affectedFlowSection($result->affectedFlows),
            $this->semanticImpactSection($result->semanticImpact),
            $this->gitHistorySection($result->churn),
            $this->dependencySection($result->dependencies),
            $this->graphSection($result->graph),
            $this->reverseDependencySection($result->reverseGraph),
            $this->aiExplanationSection($explanation),
            $this->aiRiskExplanationSection($riskExplanation),
            $this->aiTestRecommendationSection($testRecommendation),
        ));
    }

    /**
     * @return list<string>
     */
    private function aiRiskExplanationSection(?AIRiskExplanation $explanation): array
    {
        if ($explanation === null || !$explanation->wasGenerated()) {
            return [];
        }

        return [
            '',
            'AI risk explanation:',
            '  ' . $explanation->text,
        ];
    }

    /**
     * @return list<string>
     */
    private function aiTestRecommendationSection(?AITestRecommendation $recommendation): array
    {
        if ($recommendation === null || !$recommendation->wasGenerated()) {
            return [];
        }

        return [
            '',
            'AI test recommendations',
            '────────────────────────',
            $recommendation->text,
        ];
    }

    /**
     * @return list<string>
     */
    private function aiExplanationSection(?AIPrExplanation $explanation): array
    {
        if ($explanation === null || !$explanation->wasGenerated()) {
            return [];
        }

        return [
            '',
            'AI explanation:',
            $explanation->text,
        ];
    }

    /**
     * @return list<string>
     */
    private function repositoryIndexSection(?RepositoryIndex $index): array
    {
        $index ??= RepositoryIndex::empty();

        return [
            '',
            'Repository index:',
            '  PHP files: ' . $index->phpFileCount(),
            '  Symbols: ' . $index->symbolCount(),
            '  Dependencies: ' . $index->dependencyCount(),
        ];
    }

    /**
     * @return list<string>
     */
    private function changedSymbolSection(?ChangedSymbolResult $changedSymbols): array
    {
        $result = $changedSymbols ?? ChangedSymbolResult::empty();
        $lines = [
            '',
            'Changed symbols:',
        ];

        if ($result->changedSymbols === []) {
            $lines[] = '  None';
        } else {
            foreach ($result->changedSymbols as $changedSymbol) {
                $lines[] = '';
                $lines[] = '  ' . $this->symbolLabel($changedSymbol);
                $lines[] = '    lines: ' . implode(', ', $changedSymbol->changedLines);
            }
        }

        if ($result->unmappedLines !== []) {
            $lines[] = '';
            $lines[] = 'Unmapped changed lines:';
            foreach ($result->unmappedLines as $unmapped) {
                $lines[] = '  ' . $unmapped->file . ': ' . implode(', ', $unmapped->lines);
            }
        }

        if ($result->unmappedDeletedLines !== []) {
            $lines[] = '';
            $lines[] = 'Unmapped deleted lines:';
            foreach ($result->unmappedDeletedLines as $unmapped) {
                $lines[] = '  ' . $unmapped->file . ': ' . implode(', ', $unmapped->lines);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function directImpactSection(?DirectImpactResult $directImpact): array
    {
        $result = $directImpact ?? DirectImpactResult::empty();
        $lines = [
            '',
            'Direct impact:',
        ];

        if ($result->isEmpty()) {
            $lines[] = '  None';

            return $lines;
        }

        foreach ($result->getImpactedSymbols() as $impacted) {
            $lines[] = '';
            $lines[] = '  ' . $this->impactedSymbolLabel($impacted);
            foreach ($this->directImpactReasons($impacted) as $reason) {
                $lines[] = '    ← ' . $reason;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function directImpactReasons(ImpactedSymbol $impacted): array
    {
        $types = [];
        foreach ($impacted->edges as $edge) {
            $types[$edge->type->value] = true;
        }

        $reasons = array_keys($types);
        sort($reasons, SORT_STRING);

        return $reasons;
    }

    private function impactedSymbolLabel(ImpactedSymbol $impacted): string
    {
        return $this->symbolIdLabel($impacted->id);
    }

    private function symbolIdLabel(string $id): string
    {
        if (str_contains($id, '::')) {
            return $id . '()';
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private function blastRadiusSection(?TransitiveImpactResult $blastRadius): array
    {
        $result = $blastRadius ?? TransitiveImpactResult::empty();
        $lines = [
            '',
            'Blast radius:',
        ];

        if ($result->isEmpty()) {
            $lines[] = '  None';

            return $lines;
        }

        $currentDepth = null;
        foreach ($result->entries as $entry) {
            if ($entry->depth !== $currentDepth) {
                if ($currentDepth !== null) {
                    $lines[] = '';
                }
                $lines[] = '  Depth ' . $entry->depth . ':';
                $currentDepth = $entry->depth;
            }

            $lines[] = '    ' . $this->symbolIdLabel($entry->impactedSymbolId);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function testImpactSection(?TestImpactResult $testImpact): array
    {
        $result = $testImpact ?? TestImpactResult::empty();
        $lines = [
            '',
            'Test impact:',
        ];
        if ($result->isEmpty()) {
            $lines[] = '  None';

            return $lines;
        }

        $direct = [];
        $indirect = [];
        foreach ($result->uniqueTests() as $impact) {
            if ($impact->impact === TestImpactType::Direct) {
                $direct[] = $impact;
            } else {
                $indirect[] = $impact;
            }
        }

        if ($direct !== []) {
            $lines[] = '  Direct:';
            $lines = array_merge($lines, $this->testImpactGroupLines($direct, false));
        }

        if ($direct !== [] && $indirect !== []) {
            $lines[] = '';
        }

        if ($indirect !== []) {
            $lines[] = '  Indirect:';
            $lines = array_merge($lines, $this->testImpactGroupLines($indirect, true));
        }

        return $lines;
    }

    /**
     * @param list<TestImpact> $impacts
     * @return list<string>
     */
    private function testImpactGroupLines(array $impacts, bool $showDepth): array
    {
        $lines = [];
        $currentFile = null;
        foreach ($impacts as $impact) {
            if ($impact->test->file !== $currentFile) {
                $currentFile = $impact->test->file;
                $lines[] = '    ' . $currentFile;
            }

            $lines[] = '      ' . $impact->test->id;
            if ($showDepth) {
                $lines[] = '        depth: ' . $impact->depth;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function riskScoreSection(?RiskScoreResult $riskScore): array
    {
        $result = $riskScore ?? RiskScoreResult::none();
        $score = $result->score()->value();
        $level = ucfirst($result->level()->value);

        return [
            '',
            'Risk score:',
            '  ' . $score . '/100 — ' . $level,
        ];
    }

    /**
     * @return list<string>
     */
    private function riskFactorSection(?RiskFactorResult $riskFactors): array
    {
        $result = $riskFactors ?? RiskFactorResult::empty();
        if ($result->isEmpty()) {
            return [];
        }

        $lines = [
            '',
            'Risk factors:',
        ];

        foreach ($result->all() as $factor) {
            $lines[] = '';
            $lines[] = $this->severityIcon($factor->severity) . ' ' . $factor->title;
            foreach (explode("\n", $factor->description) as $descriptionLine) {
                $lines[] = '   ' . $descriptionLine;
            }
        }

        return $lines;
    }

    private function severityIcon(RiskSeverity $severity): string
    {
        return match ($severity) {
            RiskSeverity::Info => 'ℹ',
            RiskSeverity::Warning, RiskSeverity::High => '⚠',
        };
    }

    /**
     * @return list<string>
     */
    private function affectedFlowSection(?AffectedFlowResult $affectedFlows): array
    {
        $result = $affectedFlows ?? AffectedFlowResult::empty();
        if ($result->isEmpty()) {
            return [];
        }

        $lines = [
            '',
            'Affected flows:',
        ];

        foreach ($result->all() as $flow) {
            $lines[] = '';
            $lines[] = '  [' . $flow->type->value . ']';
            $lines[] = '    ' . $this->symbolIdLabel($flow->changedSymbolId);
            foreach ($flow->nodes as $node) {
                $lines[] = '      → ' . $this->symbolIdLabel($node->id);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function semanticImpactSection(?SemanticImpactResult $semanticImpact): array
    {
        $result = $semanticImpact ?? SemanticImpactResult::empty();
        if ($result->isEmpty()) {
            return [];
        }

        $lines = [
            '',
            'Semantic impact:',
        ];
        $blastRadius = $this->semanticContextLines('Blast radius:', $result->blastRadiusAnnotations);
        $affectedFlows = $this->semanticContextLines('Affected flows:', $result->affectedFlowAnnotations);
        $lines = array_merge($lines, $blastRadius);
        if ($blastRadius !== [] && $affectedFlows !== []) {
            $lines[] = '';
        }

        return array_merge($lines, $affectedFlows);
    }

    /**
     * @return list<string>
     */
    private function gitHistorySection(?ChurnResult $churn): array
    {
        $result = $churn ?? ChurnResult::empty();
        if ($result->isEmpty()) {
            return [];
        }

        $lines = [
            '',
            'Git history:',
        ];
        foreach ($result->files as $file) {
            $lines[] = '  ' . $file->file;
            $lines[] = '    commits: ' . $file->commitCount;
            $lines[] = '    lines added: ' . $file->linesAdded;
            $lines[] = '    lines deleted: ' . $file->linesDeleted;
            $lines[] = '    contributors: ' . $file->contributorsCount;
            $lines[] = '    last changed: ' . $this->formatLastChanged($file);
        }

        return $lines;
    }

    private function formatLastChanged(FileChurn $file): string
    {
        if (!$file->lastChangedAt instanceof DateTimeImmutable) {
            return 'none';
        }

        return $file->lastChangedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s') . ' UTC';
    }

    /**
     * @param list<SemanticAnnotation> $annotations
     * @return list<string>
     */
    private function semanticContextLines(string $heading, array $annotations): array
    {
        if ($annotations === []) {
            return [];
        }

        $lines = [
            '  ' . $heading,
        ];
        $currentType = null;
        foreach ($annotations as $annotation) {
            if ($currentType !== $annotation->type) {
                $currentType = $annotation->type;
                $lines[] = '    [' . $annotation->type->value . ']';
            }
            $lines[] = '      ' . $this->symbolIdLabel($annotation->symbolId);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function dependencySection(?DependencyResult $dependencies): array
    {
        $result = $dependencies ?? DependencyResult::empty();
        $lines = [
            '',
            'Dependencies:',
        ];

        if ($result->dependencies === []) {
            $lines[] = '  None';

            return $lines;
        }

        foreach ($result->dependencies as $dependency) {
            $lines[] = '';
            $lines[] = '  ' . $dependency->source;
            $lines[] = '    → ' . $dependency->target;
            $lines[] = '      type: ' . $dependency->type->value;
            $lines[] = '      line: ' . $dependency->line();
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function graphSection(?DependencyGraph $graph): array
    {
        $graph ??= new DependencyGraph();
        $nodes = $graph->getNodes();
        $edges = $graph->getEdges();
        $lines = [
            '',
            'Dependency graph:',
            '  Nodes: ' . count($nodes),
            '  Edges: ' . count($edges),
        ];

        $currentSource = null;
        foreach ($edges as $edge) {
            if ($edge->source !== $currentSource) {
                $lines[] = '';
                $lines[] = '  ' . $edge->source;
                $currentSource = $edge->source;
            }

            $lines[] = $this->graphEdgeLine($edge);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function reverseDependencySection(?ReverseDependencyGraph $reverseGraph): array
    {
        $reverseGraph ??= new ReverseDependencyGraph([], []);
        $lines = [
            '',
            'Reverse dependencies:',
        ];

        $printed = false;
        foreach ($reverseGraph->getNodes() as $node) {
            $edges = $reverseGraph->getDependentEdges($node->id);
            if ($edges === []) {
                continue;
            }

            $printed = true;
            $lines[] = '';
            $lines[] = '  ' . $node->id;
            foreach ($edges as $edge) {
                $lines[] = '    ← ' . $edge->source . ' [' . $edge->type->value . ']';
            }
        }

        if (!$printed) {
            $lines[] = '  None';
        }

        return $lines;
    }

    private function graphEdgeLine(GraphEdge $edge): string
    {
        return '    → ' . $edge->target . ' [' . $edge->type->value . ']';
    }

    private function symbolLabel(ChangedSymbol $changedSymbol): string
    {
        $name = $changedSymbol->symbol->fullyQualifiedName;
        if (
            $changedSymbol->symbol->type === SymbolType::Method
            || $changedSymbol->symbol->type === SymbolType::Function
        ) {
            return $name . '()';
        }

        return $name;
    }

    private function fileHeading(ChangedFile $file): string
    {
        $letter = match ($file->changeType) {
            ChangeType::Modified => 'M',
            ChangeType::Added => 'A',
            ChangeType::Deleted => 'D',
            ChangeType::Renamed => 'R',
        };

        if ($file->changeType === ChangeType::Renamed && $file->oldPath !== null) {
            return sprintf('%s %s → %s', $letter, $file->oldPath, $file->path);
        }

        return sprintf('%s %s', $letter, $file->path);
    }

    private function countSummary(ChangedFile $file): ?string
    {
        $added = count($file->addedLines);
        $deleted = count($file->deletedLines);

        if ($added === 0 && $deleted === 0) {
            return null;
        }

        $parts = [];
        if ($added > 0) {
            $parts[] = '+' . $added;
        }
        if ($deleted > 0) {
            $parts[] = '-' . $deleted;
        }

        return '  ' . implode(' ', $parts);
    }
}
