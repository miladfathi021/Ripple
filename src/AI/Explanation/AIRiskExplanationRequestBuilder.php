<?php

declare(strict_types=1);

namespace Ripple\AI\Explanation;

use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreContribution;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\AI\AIRequest;

final class AIRiskExplanationRequestBuilder
{
    public const INSTRUCTIONS = <<<'TEXT'
You are explaining Ripple's deterministic risk assessment to a software developer.

Ripple's risk score is authoritative.
Ripple's risk level is authoritative.
The supplied risk contributions are authoritative.
Explain only supplied facts.
Never recalculate the score.
Never suggest a different risk level.
Never invent risk factors.
Never invent missing evidence.
Never invent affected components.
Never claim that the change will definitely break something.
Do not claim a factor exists unless it is supplied.
Use cautious language such as "may affect", "has a dependency on", or "Ripple identified".
Prioritize the strongest risk contributions.
Explain evidence when evidence is available.
If evidence is unavailable, do not guess.
Keep the explanation concise.
Do not discuss implementation details that are not included in the input.
TEXT;

    public function build(AnalysisResult $result): AIRequest
    {
        return new AIRequest(
            input: $this->summary($result),
            instructions: self::INSTRUCTIONS,
        );
    }

    private function summary(AnalysisResult $result): string
    {
        $riskScore = $result->riskScore ?? RiskScoreResult::none();
        $sections = [
            'Risk score: ' . $riskScore->score()->value(),
            'Risk level: ' . $riskScore->level()->value,
            $this->contributions($riskScore->contributions()),
            $this->factors($result->riskFactors ?? RiskFactorResult::empty()),
        ];

        $blastRadius = $result->blastRadius ?? TransitiveImpactResult::empty();
        if (!$blastRadius->isEmpty()) {
            $sections[] = $this->blastRadius($blastRadius);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param list<RiskScoreContribution> $contributions
     */
    private function contributions(array $contributions): string
    {
        if ($contributions === []) {
            return "Contributions:\n  none";
        }

        $blocks = [];
        foreach ($contributions as $contribution) {
            $blocks[] = implode("\n", [
                $contribution->code,
                '  title: ' . $contribution->title,
                '  severity: ' . $contribution->severity->value,
                '  max_weight: ' . $contribution->maxWeight,
                '  contribution: ' . $contribution->contribution,
            ]);
        }

        return "Contributions:\n" . implode("\n\n", $blocks);
    }

    private function factors(RiskFactorResult $factors): string
    {
        if ($factors->isEmpty()) {
            return "Risk factors:\n  none";
        }

        $blocks = [];
        foreach ($factors->all() as $factor) {
            $lines = [
                $factor->code,
                '  title: ' . $factor->title,
                '  severity: ' . $factor->severity->value,
                '  description: ' . $factor->description,
            ];
            $evidence = $this->evidenceLines($factor);
            if ($evidence === []) {
                $lines[] = '  evidence: none';
            } else {
                $lines[] = '  evidence:';
                foreach ($evidence as $line) {
                    $lines[] = '    ' . $line;
                }
            }
            $blocks[] = implode("\n", $lines);
        }

        return "Risk factors:\n" . implode("\n\n", $blocks);
    }

    /**
     * @return list<string>
     */
    private function evidenceLines(RiskFactor $factor): array
    {
        if ($factor->evidence === []) {
            return [];
        }

        $encoded = json_encode($factor->evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return [];
        }

        return [$encoded];
    }

    private function blastRadius(TransitiveImpactResult $blastRadius): string
    {
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
}
