<?php

declare(strict_types=1);

namespace Ripple\AI\Explanation;

use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Semantics\SemanticImpactResult;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;
use Ripple\AI\AIRequest;
use Ripple\Git\DiffResult;
use Ripple\Git\History\ChurnResult;

final class AIPrExplanationRequestBuilder
{
    public const INSTRUCTIONS = <<<'TEXT'
You are explaining a code impact analysis to a developer.

Use only the supplied analysis facts.
Do not invent dependencies, affected components, risks, or failures.
Do not claim that something will definitely break.
Use language such as "may affect", "can reach", or "Ripple identified".
Treat the risk score and risk level as authoritative.
Do not recalculate the risk score.
Keep the explanation concise.
Focus on what changed, what may be affected, and why the change deserves attention.
If there is insufficient information, say so rather than guessing.
TEXT;

    private const MAX_LIST_ITEMS = 20;

    public function build(AnalysisResult $result): AIRequest
    {
        return new AIRequest(
            input: $this->summary($result),
            instructions: self::INSTRUCTIONS,
        );
    }

    private function summary(AnalysisResult $result): string
    {
        $sections = [
            $this->changedFiles($result->diff ?? new DiffResult([])),
            $this->changedSymbols($result->changedSymbols ?? ChangedSymbolResult::empty()),
            $this->risk($result->riskScore ?? RiskScoreResult::none()),
            $this->riskFactors($result->riskFactors ?? RiskFactorResult::empty()),
            $this->blastRadius($result->blastRadius ?? TransitiveImpactResult::empty()),
            $this->affectedFlows($result->affectedFlows ?? AffectedFlowResult::empty()),
            $this->semanticImpact($result->semanticImpact ?? SemanticImpactResult::empty()),
            $this->testImpact($result->testImpact ?? TestImpactResult::empty()),
            $this->churn($result->churn ?? ChurnResult::empty()),
        ];

        return implode("\n\n", $sections);
    }

    private function changedFiles(DiffResult $diff): string
    {
        $paths = [];
        foreach ($diff->files as $file) {
            $paths[] = $file->path;
        }

        return $this->block('Changed files', $paths);
    }

    private function changedSymbols(ChangedSymbolResult $changedSymbols): string
    {
        $ids = [];
        foreach ($changedSymbols->changedSymbols as $changedSymbol) {
            $ids[] = $changedSymbol->symbol->fullyQualifiedName;
        }

        return $this->block('Changed symbols', $ids);
    }

    private function risk(RiskScoreResult $riskScore): string
    {
        return "Risk:\n  " . $riskScore->score()->value() . ' / ' . $riskScore->level()->value;
    }

    private function riskFactors(RiskFactorResult $riskFactors): string
    {
        $codes = [];
        foreach ($riskFactors->all() as $factor) {
            if (!in_array($factor->code, $codes, true)) {
                $codes[] = $factor->code;
            }
        }

        return $this->block('Risk factors', $codes);
    }

    private function blastRadius(TransitiveImpactResult $blastRadius): string
    {
        if ($blastRadius->isEmpty()) {
            return $this->block('Blast radius', []);
        }

        $counts = [];
        foreach ($blastRadius->entries as $entry) {
            $counts[$entry->depth] = ($counts[$entry->depth] ?? 0) + 1;
        }
        ksort($counts, SORT_NUMERIC);

        $lines = ['symbols: ' . $blastRadius->count()];
        foreach ($counts as $depth => $count) {
            $lines[] = 'depth ' . $depth . ': ' . $count;
        }

        return "Blast radius:\n  " . implode("\n  ", $lines);
    }

    private function affectedFlows(AffectedFlowResult $affectedFlows): string
    {
        $ids = [];
        foreach ($affectedFlows->all() as $flow) {
            foreach ($flow->nodes as $node) {
                if ($node->id !== '' && !in_array($node->id, $ids, true)) {
                    $ids[] = $node->id;
                }
            }
        }

        return $this->block('Affected flows', $ids);
    }

    private function semanticImpact(SemanticImpactResult $semanticImpact): string
    {
        if ($semanticImpact->isEmpty()) {
            return $this->block('Semantic impact', []);
        }

        $lines = [];
        foreach ($semanticImpact->blastRadiusAnnotations as $annotation) {
            $lines[] = 'blast radius: ' . $annotation->type->value . ' ' . $annotation->symbolId;
        }
        foreach ($semanticImpact->affectedFlowAnnotations as $annotation) {
            $lines[] = 'affected flows: ' . $annotation->type->value . ' ' . $annotation->symbolId;
        }

        return $this->block('Semantic impact', $lines);
    }

    private function testImpact(TestImpactResult $testImpact): string
    {
        if ($testImpact->isEmpty()) {
            return $this->block('Test impact', []);
        }

        $direct = 0;
        $indirect = 0;
        foreach ($testImpact->uniqueTests() as $impact) {
            if ($impact->impact === TestImpactType::Direct) {
                $direct++;
            } else {
                $indirect++;
            }
        }

        return "Test impact:\n  direct: {$direct}\n  indirect: {$indirect}";
    }

    private function churn(ChurnResult $churn): string
    {
        $lines = [];
        foreach ($churn->files as $file) {
            $lines[] = $file->file . ': ' . $file->commitCount . ' commits';
        }

        return $this->block('Historical churn', $lines);
    }

    /**
     * @param list<string> $items
     */
    private function block(string $title, array $items): string
    {
        if ($items === []) {
            return $title . ":\n  none";
        }

        $shown = array_slice($items, 0, self::MAX_LIST_ITEMS);
        $lines = $title . ":\n  " . implode("\n  ", $shown);
        $omitted = count($items) - count($shown);
        if ($omitted > 0) {
            $lines .= "\n  and {$omitted} more";
        }

        return $lines;
    }
}
