<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\AST\SymbolType;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Risk\Rules\HistoricalChurnRule;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;

final class PullRequestCommentFormatter implements ReportFormatter
{
    public const MARKER = '<!-- ripple-analysis -->';

    private const DIVIDER = '────────────────────────';

    private const MAX_CHANGED_SYMBOLS = 8;

    private const MAX_AFFECTED_FLOWS = 8;

    private const MAX_CHURN_FILES = 5;

    public function format(
        AnalysisResult $result,
        ?AIPrExplanation $explanation = null,
        ?AIRiskExplanation $riskExplanation = null,
    ): string
    {
        return self::MARKER . "\n" . $this->body($result, $explanation, $riskExplanation);
    }

    private function body(
        AnalysisResult $result,
        ?AIPrExplanation $explanation,
        ?AIRiskExplanation $riskExplanation,
    ): string
    {
        if (!$result->isSuccessful()) {
            return implode("\n", [
                '🌊 Ripple — Code Impact Analysis',
                '',
                'Analysis did not complete successfully.',
                '',
                $result->message,
                '',
                'See full analysis in the workflow artifact.',
            ]);
        }

        $lines = [
            '🌊 Ripple — Code Impact Analysis',
            '',
            $this->riskLine($result->riskScore),
        ];

        return implode("\n", array_merge(
            $lines,
            $this->whyThisRiskSection($riskExplanation),
            $this->section('Changed symbols', $this->changedSymbolLines($result->changedSymbols)),
            $this->section('Blast radius', $this->blastRadiusLines($result->blastRadius)),
            $this->section('Affected flows', $this->affectedFlowLines($result->affectedFlows)),
            $this->section('Test impact', $this->testImpactLines($result->testImpact)),
            $this->section('Historical churn', $this->churnLines($result->churn)),
            $this->section('⚠ Risk factors', $this->riskFactorLines($result->riskFactors)),
            $this->aiExplanationSection($explanation),
            [
                '',
                'See full analysis in the workflow artifact.',
            ],
        ));
    }

    /**
     * @return list<string>
     */
    private function whyThisRiskSection(?AIRiskExplanation $explanation): array
    {
        if ($explanation === null || !$explanation->wasGenerated()) {
            return [];
        }

        return [
            '',
            'Why this risk?',
            $explanation->text,
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

        return $this->section('AI explanation', [$explanation->text]);
    }

    private function riskLine(?RiskScoreResult $riskScore): string
    {
        $result = $riskScore ?? RiskScoreResult::none();

        return 'Risk: ' . $result->score()->value() . ' / ' . ucfirst($result->level()->value);
    }

    /**
     * @param list<string> $body
     * @return list<string>
     */
    private function section(string $title, array $body): array
    {
        return array_merge(
            [
                '',
                $title,
                self::DIVIDER,
            ],
            $body,
        );
    }

    /**
     * @return list<string>
     */
    private function changedSymbolLines(?ChangedSymbolResult $changedSymbols): array
    {
        $symbols = ($changedSymbols ?? ChangedSymbolResult::empty())->changedSymbols;
        if ($symbols === []) {
            return ['None'];
        }

        $lines = [];
        foreach ($symbols as $changedSymbol) {
            $lines[] = $this->changedSymbolLabel($changedSymbol);
        }

        return $this->summarize($lines, self::MAX_CHANGED_SYMBOLS);
    }

    /**
     * @return list<string>
     */
    private function blastRadiusLines(?TransitiveImpactResult $blastRadius): array
    {
        $entries = ($blastRadius ?? TransitiveImpactResult::empty())->entries;
        if ($entries === []) {
            return ['None'];
        }

        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry->depth] = ($counts[$entry->depth] ?? 0) + 1;
        }
        ksort($counts, SORT_NUMERIC);

        $lines = [];
        foreach ($counts as $depth => $count) {
            $lines[] = 'Depth ' . $depth . ': ' . $count . ' ' . ($count === 1 ? 'symbol' : 'symbols');
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function affectedFlowLines(?AffectedFlowResult $affectedFlows): array
    {
        $result = $affectedFlows ?? AffectedFlowResult::empty();
        if ($result->isEmpty()) {
            return ['None'];
        }

        $names = [];
        foreach ($result->all() as $flow) {
            foreach ($flow->nodes as $node) {
                $name = $this->shortClassName($node->id);
                if ($name !== '' && !isset($names[$name])) {
                    $names[$name] = true;
                }
            }
        }

        if ($names === []) {
            return ['None'];
        }

        $labels = array_keys($names);
        sort($labels, SORT_STRING);

        $lines = [];
        foreach ($labels as $name) {
            $lines[] = '→ ' . $name;
        }

        return $this->summarize($lines, self::MAX_AFFECTED_FLOWS);
    }

    /**
     * @return list<string>
     */
    private function testImpactLines(?TestImpactResult $testImpact): array
    {
        $result = $testImpact ?? TestImpactResult::empty();
        if ($result->isEmpty()) {
            return ['None'];
        }

        $direct = 0;
        $indirect = 0;
        foreach ($result->uniqueTests() as $impact) {
            if ($impact->impact === TestImpactType::Direct) {
                $direct++;
            } else {
                $indirect++;
            }
        }

        $lines = [];
        if ($direct > 0) {
            $lines[] = '✓ ' . $direct . ' ' . ($direct === 1 ? 'direct test' : 'direct tests');
        }
        if ($indirect > 0) {
            $lines[] = '⚠ ' . $indirect . ' ' . ($indirect === 1 ? 'indirect test' : 'indirect tests');
        }

        return $lines === [] ? ['None'] : $lines;
    }

    /**
     * @return list<string>
     */
    private function churnLines(?ChurnResult $churn): array
    {
        $files = [];
        foreach (($churn ?? ChurnResult::empty())->files as $file) {
            if ($file->commitCount < HistoricalChurnRule::INFO_MIN_COMMITS) {
                continue;
            }
            $files[] = $file;
        }

        if ($files === []) {
            return ['None'];
        }

        usort(
            $files,
            static fn (FileChurn $left, FileChurn $right): int => [
                -$left->commitCount,
                $left->file,
            ] <=> [
                -$right->commitCount,
                $right->file,
            ],
        );

        $shown = array_slice($files, 0, self::MAX_CHURN_FILES);
        $lines = [];
        foreach ($shown as $index => $file) {
            if ($index > 0) {
                $lines[] = '';
            }
            $lines[] = basename($file->file);
            $lines[] = $file->commitCount . ' ' . ($file->commitCount === 1 ? 'commit' : 'commits');
        }

        $omitted = count($files) - count($shown);
        if ($omitted > 0) {
            $lines[] = '… and ' . $omitted . ' more';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function riskFactorLines(?RiskFactorResult $riskFactors): array
    {
        $titles = [];
        foreach (($riskFactors ?? RiskFactorResult::empty())->all() as $factor) {
            if ($factor->code === RiskFactor::CODE_HISTORICAL_CHURN) {
                continue;
            }
            $titles[] = $factor->title;
        }

        return $titles === [] ? ['None'] : $titles;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function summarize(array $lines, int $limit): array
    {
        if (count($lines) <= $limit) {
            return $lines;
        }

        return array_merge(
            array_slice($lines, 0, $limit),
            ['… and ' . (count($lines) - $limit) . ' more'],
        );
    }

    private function changedSymbolLabel(ChangedSymbol $changedSymbol): string
    {
        $label = $this->shortSymbolId($changedSymbol->symbol->fullyQualifiedName);
        if (
            $changedSymbol->symbol->type === SymbolType::Method
            || $changedSymbol->symbol->type === SymbolType::Function
        ) {
            return $label . '()';
        }

        return $label;
    }

    private function shortSymbolId(string $id): string
    {
        $suffix = '';
        $class = $id;
        if (str_contains($id, '::')) {
            [$class, $method] = explode('::', $id, 2);
            $suffix = '::' . $method;
        }

        return $this->shortClassName($class) . $suffix;
    }

    private function shortClassName(string $id): string
    {
        $class = $id;
        if (str_contains($id, '::')) {
            $class = explode('::', $id, 2)[0];
        }

        if (str_contains($class, '\\')) {
            return substr($class, strrpos($class, '\\') + 1);
        }

        return $class;
    }
}
