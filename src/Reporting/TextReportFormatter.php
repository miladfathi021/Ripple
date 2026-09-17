<?php

declare(strict_types=1);

namespace Ripple\Reporting;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\AI\Testing\AITestRecommendation;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;

final class TextReportFormatter implements ReportFormatter
{
    private const MAX_PROSE_CHARACTERS = 480;

    private const WRAP_WIDTH = 88;

    private const MAX_RECOMMENDATIONS = 5;

    private const MAX_RECOMMENDATION_CHARACTERS = 160;

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

        $lines = [
            'Ripple Analysis',
            $this->underline('Ripple Analysis'),
            $this->riskLine($result->riskScore),
            $this->changedFilesLine($result),
            $this->blastRadiusLine($result->blastRadius),
            $this->affectedTestsLine($result->testImpact),
        ];

        return implode("\n", array_merge(
            $lines,
            $this->aiSection('AI Explanation', $explanation?->wasGenerated() === true ? $explanation->text : null),
            $this->aiSection('AI Risk', $riskExplanation?->wasGenerated() === true ? $riskExplanation->text : null),
            $this->aiTestRecommendationSection($testRecommendation),
        ));
    }

    /**
     * @return list<string>
     */
    private function aiSection(string $title, ?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        return array_merge(
            ['', $title, $this->underline($title)],
            $this->presentProse($text),
        );
    }

    /**
     * @return list<string>
     */
    private function aiTestRecommendationSection(?AITestRecommendation $recommendation): array
    {
        if ($recommendation === null || !$recommendation->wasGenerated()) {
            return [];
        }

        $items = $this->recommendationItems($recommendation->text);
        $shown = array_slice($items, 0, self::MAX_RECOMMENDATIONS);
        $hidden = count($items) - count($shown);

        $lines = [
            '',
            'AI Test Recommendations',
            $this->underline('AI Test Recommendations'),
        ];
        foreach ($shown as $index => $item) {
            $lines[] = ($index + 1) . '. ' . $this->truncate($item, self::MAX_RECOMMENDATION_CHARACTERS);
        }
        if ($hidden > 0) {
            $lines[] = '... and ' . $hidden . ' more recommendation' . ($hidden === 1 ? '' : 's');
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function presentProse(string $text): array
    {
        $wrapped = wordwrap($this->truncate(trim($text), self::MAX_PROSE_CHARACTERS), self::WRAP_WIDTH, "\n", true);

        return explode("\n", $wrapped);
    }

    /**
     * @return list<string>
     */
    private function recommendationItems(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/u', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $hadPrefix = preg_match('/^(?:[-*]|\\d+[.)])\\s+/', $line) === 1;
            $stripped = preg_replace('/^(?:[-*]|\\d+[.)])\\s+/', '', $line);
            $item = is_string($stripped) && $stripped !== '' ? $stripped : $line;

            $continuesPrevious = !$hadPrefix
                && $items !== []
                && preg_match('/^[[:lower:]]/u', $item) === 1;
            if ($continuesPrevious) {
                $items[array_key_last($items)] .= ' ' . $item;
                continue;
            }

            $items[] = $item;
        }

        return $items === [] ? [trim($text)] : $items;
    }

    private function truncate(string $text, int $maxCharacters): string
    {
        $normalized = trim(preg_replace('/[ \\t]+/', ' ', $text) ?? $text);
        if (strlen($normalized) <= $maxCharacters) {
            return $normalized;
        }

        $slice = substr($normalized, 0, $maxCharacters);
        $break = strrpos($slice, ' ');
        if ($break !== false && $break > (int) ($maxCharacters * 0.6)) {
            $slice = substr($slice, 0, $break);
        }

        return rtrim($slice, " \t.,;:") . '...';
    }

    private function underline(string $title): string
    {
        return str_repeat('─', strlen($title));
    }

    private function riskLine(?RiskScoreResult $riskScore): string
    {
        $result = $riskScore ?? RiskScoreResult::none();

        return 'Risk: ' . $result->score()->value() . ' / 100 (' . ucfirst($result->level()->value) . ')';
    }

    private function changedFilesLine(AnalysisResult $result): string
    {
        return 'Changed files: ' . count($result->diff?->files ?? []);
    }

    private function blastRadiusLine(?TransitiveImpactResult $blastRadius): string
    {
        $count = ($blastRadius ?? TransitiveImpactResult::empty())->count();

        return 'Blast radius: ' . $count . ' symbol' . ($count === 1 ? '' : 's');
    }

    private function affectedTestsLine(?TestImpactResult $testImpact): string
    {
        $direct = 0;
        $indirect = 0;
        foreach (($testImpact ?? TestImpactResult::empty())->uniqueTests() as $impact) {
            if ($impact->impact === TestImpactType::Direct) {
                $direct++;
            } else {
                $indirect++;
            }
        }

        return 'Affected tests: ' . $direct . ' direct, ' . $indirect . ' indirect';
    }
}
