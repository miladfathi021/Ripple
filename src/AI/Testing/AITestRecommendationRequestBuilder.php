<?php

declare(strict_types=1);

namespace Ripple\AI\Testing;

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
use Ripple\Git\History\ChurnResult;

final class AITestRecommendationRequestBuilder
{
    public const INSTRUCTIONS = <<<'TEXT'
You are generating test recommendations from deterministic Ripple analysis.

Rules:
1. Recommend tests only from the supplied evidence.
2. Never claim that a test already exists unless Ripple explicitly supplied that test.
3. Never claim that code is untested unless the supplied evidence proves that.
4. Never invent test names, classes, files, or methods.
5. If recommending a new test, clearly say it is a recommendation, not an existing test.
6. Prefer existing impacted tests when suggesting tests to review or update.
7. Distinguish:
   * existing impacted test
   * existing directly impacted test
   * existing indirectly impacted test
   * suggested new regression test
8. Do not claim a test will definitely fail.
9. Do not claim a bug exists.
10. Do not claim code coverage percentages.
11. Do not invent runtime behavior.
12. Do not calculate or modify Ripple's risk score.
13. Do not repeat large amounts of deterministic analysis unnecessarily.
14. Keep recommendations concise and actionable.
15. If there is insufficient evidence for a recommendation, say so rather than guessing.

Use cautious language such as "Consider updating...", "Consider adding...", "Review...", or "This change directly affects...".
Avoid "This test will fail.", "This definitely breaks...", and "There is no test for..." unless that exact fact is explicitly supplied.
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
        return implode("\n\n", [
            $this->changedSymbols($result->changedSymbols ?? ChangedSymbolResult::empty()),
            $this->testImpact($result->testImpact ?? TestImpactResult::empty()),
            $this->blastRadius($result->blastRadius ?? TransitiveImpactResult::empty()),
            $this->affectedFlows($result->affectedFlows ?? AffectedFlowResult::empty()),
            $this->risk($result->riskScore ?? RiskScoreResult::none(), $result->riskFactors ?? RiskFactorResult::empty()),
            $this->semanticImpact($result->semanticImpact ?? SemanticImpactResult::empty()),
            $this->churn($result->churn ?? ChurnResult::empty()),
        ]);
    }

    private function changedSymbols(ChangedSymbolResult $changedSymbols): string
    {
        $lines = [];
        foreach ($changedSymbols->changedSymbols as $changedSymbol) {
            $symbol = $changedSymbol->symbol;
            $lines[] = $symbol->fullyQualifiedName
                . ' type=' . $symbol->type->value
                . ' file=' . $symbol->file
                . ' lines=' . implode(',', $changedSymbol->changedLines);
        }

        return $this->block('Changed symbols', $lines);
    }

    private function testImpact(TestImpactResult $testImpact): string
    {
        if ($testImpact->isEmpty()) {
            return $this->block('Test impact', []);
        }

        $direct = [];
        $indirect = [];
        foreach ($testImpact->uniqueTests() as $impact) {
            $line = $impact->test->id
                . ' type=' . $impact->test->type->value
                . ' file=' . $impact->test->file
                . ' production_symbol=' . $impact->changedSymbolId
                . ' depth=' . $impact->depth
                . ' impact=' . $impact->impact->value;
            if ($impact->impact === TestImpactType::Direct) {
                $direct[] = $line;
            } else {
                $indirect[] = $line;
            }
        }

        $sections = ['Test impact:'];
        $sections[] = $this->indentedBlock('directly_impacted_tests', $direct);
        $sections[] = $this->indentedBlock('indirectly_impacted_tests', $indirect);

        return implode("\n", $sections);
    }

    private function blastRadius(TransitiveImpactResult $blastRadius): string
    {
        if ($blastRadius->isEmpty()) {
            return $this->block('Blast radius', []);
        }

        $lines = ['symbols: ' . $blastRadius->count()];
        foreach (array_slice($blastRadius->entries, 0, self::MAX_LIST_ITEMS) as $entry) {
            $origin = $entry->origins[0] ?? null;
            $line = $entry->impactedSymbolId . ' depth=' . $entry->depth;
            if ($origin !== null) {
                $line .= ' origin=' . $origin->changedSymbolId
                    . ' origin_depth=' . $origin->depth
                    . ' edge=' . $origin->edge->type->value
                    . ' ' . $origin->edge->source . '->' . $origin->edge->target;
            }
            $lines[] = $line;
        }

        return $this->block('Blast radius', $lines);
    }

    private function affectedFlows(AffectedFlowResult $affectedFlows): string
    {
        $lines = [];
        foreach ($affectedFlows->all() as $flow) {
            $nodes = [];
            foreach ($flow->nodes as $node) {
                $nodes[] = $node->id;
            }
            $lines[] = $flow->changedSymbolId
                . ' type=' . $flow->type->value
                . ' depth=' . $flow->depth
                . ' nodes=' . implode(' -> ', $nodes);
        }

        return $this->block('Affected flows', $lines);
    }

    private function risk(RiskScoreResult $riskScore, RiskFactorResult $factors): string
    {
        $lines = [
            'score=' . $riskScore->score()->value(),
            'level=' . $riskScore->level()->value,
        ];
        foreach ($factors->all() as $factor) {
            $lines[] = $factor->code . ' severity=' . $factor->severity->value;
        }

        return $this->block('Risk', $lines);
    }

    private function semanticImpact(SemanticImpactResult $semanticImpact): string
    {
        if ($semanticImpact->isEmpty()) {
            return $this->block('Semantic impact', []);
        }

        $lines = [];
        foreach ($semanticImpact->blastRadiusAnnotations as $annotation) {
            $lines[] = 'blast_radius ' . $annotation->type->value . ' ' . $annotation->symbolId;
        }
        foreach ($semanticImpact->affectedFlowAnnotations as $annotation) {
            $lines[] = 'affected_flows ' . $annotation->type->value . ' ' . $annotation->symbolId;
        }

        return $this->block('Semantic impact', $lines);
    }

    private function churn(ChurnResult $churn): string
    {
        $lines = [];
        foreach ($churn->files as $file) {
            $lines[] = $file->file . ' commits=' . $file->commitCount;
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

        return $title . ":\n" . $this->limited($items, '  ');
    }

    /**
     * @param list<string> $items
     */
    private function indentedBlock(string $title, array $items): string
    {
        if ($items === []) {
            return '  ' . $title . ":\n    none";
        }

        return '  ' . $title . ":\n" . $this->limited($items, '    ');
    }

    /**
     * @param list<string> $items
     */
    private function limited(array $items, string $prefix): string
    {
        $shown = array_slice($items, 0, self::MAX_LIST_ITEMS);
        $lines = [];
        foreach ($shown as $item) {
            $lines[] = $prefix . $item;
        }
        $omitted = count($items) - count($shown);
        if ($omitted > 0) {
            $lines[] = $prefix . 'and ' . $omitted . ' more';
        }

        return implode("\n", $lines);
    }
}
