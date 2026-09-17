<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Explanation\AIRiskExplanationRequestBuilder;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskLevel;
use Ripple\Analysis\Risk\RiskScore;
use Ripple\Analysis\Risk\RiskScoreContribution;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Git\DiffResult;

final class AIRiskExplanationRequestBuilderTest extends TestCase
{
    public function testIncludesAuthoritativeScoreLevelContributionsAndEvidence(): void
    {
        $request = (new AIRiskExplanationRequestBuilder())->build($this->analysisResult());
        $input = $request->input;

        $this->assertSame(AIRiskExplanationRequestBuilder::INSTRUCTIONS, $request->instructions);
        $this->assertStringContainsString("Ripple's risk score is authoritative.", $request->instructions);
        $this->assertStringContainsString('Never recalculate the score.', $request->instructions);
        $this->assertStringContainsString('Never suggest a different risk level.', $request->instructions);
        $this->assertStringContainsString('Risk score: 67', $input);
        $this->assertStringContainsString('Risk level: high', $input);
        $this->assertStringContainsString("high_fan_in\n  title: High fan-in\n  severity: warning\n  max_weight: 20\n  contribution: 15", $input);
        $this->assertStringContainsString("large_blast_radius\n  title: Large blast radius\n  severity: high\n  max_weight: 20\n  contribution: 20", $input);
        $this->assertStringContainsString("historical_churn\n  title: Historical churn\n  severity: warning\n  max_weight: 25\n  contribution: 19", $input);
        $this->assertStringContainsString('ReservationService::updateStatus', $input);
        $this->assertStringContainsString('"max_commit_count":18', $input);
        $this->assertStringContainsString("Blast radius:\n  symbols: 10\n  depth 1: 3\n  depth 2: 5\n  depth 3: 2", $input);
        $this->assertStringNotContainsString('contribution: 16', $input);
        $this->assertStringNotContainsString('medium', $input);
        $this->assertStringNotContainsString('<?php', $input);
        $this->assertStringNotContainsString('public function', $input);
        $this->assertStringNotContainsString('Dependency graph', $input);
        $this->assertStringNotContainsString('RiskScoreCalculator', $input);
        $this->assertSame(1, substr_count($input, 'Risk score: 67'));
        $this->assertSame(1, substr_count($input, 'Risk level: high'));
    }

    public function testEmptyRiskUsesNonePlaceholders(): void
    {
        $input = (new AIRiskExplanationRequestBuilder())->build(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
        ))->input;

        $this->assertStringContainsString('Risk score: 0', $input);
        $this->assertStringContainsString('Risk level: low', $input);
        $this->assertStringContainsString("Contributions:\n  none", $input);
        $this->assertStringContainsString("Risk factors:\n  none", $input);
        $this->assertStringNotContainsString('Blast radius:', $input);
    }

    public function testBuilderDoesNotImportGraphOrGitAnalyzers(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AI/Explanation/AIRiskExplanationRequestBuilder.php',
        );

        $this->assertStringNotContainsString('RiskScoreCalculator', $source);
        $this->assertStringNotContainsString('TransitiveImpactAnalyzer', $source);
        $this->assertStringNotContainsString('GitHistoryAnalyzer', $source);
        $this->assertStringNotContainsString('DependencyGraphBuilder', $source);
        $this->assertStringNotContainsString('file_get_contents', $source);
    }

    private function analysisResult(): AnalysisResult
    {
        $changed = 'App\\Services\\ReservationService::updateStatus';

        return new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            blastRadius: TransitiveImpactResult::fromEntries($this->blastRadiusEntries()),
            riskFactors: RiskFactorResult::fromFactors([
                new RiskFactor(
                    RiskFactor::CODE_HIGH_FAN_IN,
                    RiskSeverity::Warning,
                    'High fan-in',
                    'ReservationService::updateStatus has 6 direct dependents.',
                    6,
                    ['symbol' => $changed],
                ),
                new RiskFactor(
                    RiskFactor::CODE_LARGE_BLAST_RADIUS,
                    RiskSeverity::High,
                    'Large blast radius',
                    '10 symbols may be affected by this change.',
                    10,
                    ['count' => 10],
                ),
                new RiskFactor(
                    RiskFactor::CODE_HISTORICAL_CHURN,
                    RiskSeverity::Warning,
                    'Historical churn',
                    'ReservationService.php has changed 18 times in Git history.',
                    18,
                    ['max_commit_count' => 18],
                ),
            ]),
            riskScore: new RiskScoreResult(
                RiskScore::of(67),
                RiskLevel::High,
                [
                    new RiskScoreContribution(
                        RiskFactor::CODE_HIGH_FAN_IN,
                        'High fan-in',
                        RiskSeverity::Warning,
                        20,
                        15,
                    ),
                    new RiskScoreContribution(
                        RiskFactor::CODE_LARGE_BLAST_RADIUS,
                        'Large blast radius',
                        RiskSeverity::High,
                        20,
                        20,
                    ),
                    new RiskScoreContribution(
                        RiskFactor::CODE_HISTORICAL_CHURN,
                        'Historical churn',
                        RiskSeverity::Warning,
                        25,
                        19,
                    ),
                ],
            ),
        );
    }

    /**
     * @return list<BlastRadiusEntry>
     */
    private function blastRadiusEntries(): array
    {
        $entries = [];
        for ($i = 1; $i <= 3; $i++) {
            $entries[] = new BlastRadiusEntry('Depth1Symbol' . $i, 1, []);
        }
        for ($i = 1; $i <= 5; $i++) {
            $entries[] = new BlastRadiusEntry('Depth2Symbol' . $i, 2, []);
        }
        for ($i = 1; $i <= 2; $i++) {
            $entries[] = new BlastRadiusEntry('Depth3Symbol' . $i, 3, []);
        }

        return $entries;
    }
}
