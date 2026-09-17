<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Flow\AffectedFlow;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Flow\FlowNode;
use Ripple\Analysis\Flow\FlowType;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskLevel;
use Ripple\Analysis\Risk\RiskScore;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Tests\TestImpact;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;
use Ripple\Analysis\Tests\TestSymbol;
use Ripple\Analysis\Tests\TestSymbolType;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\Git\DiffResult;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;
use Ripple\Reporting\PullRequestCommentFormatter;

final class PullRequestCommentFormatterTest extends TestCase
{
    public function testFormatsTheCompactPullRequestComment(): void
    {
        $changed = 'App\\Services\\ReservationService::updateStatus';
        $output = (new PullRequestCommentFormatter())->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            changedSymbols: new ChangedSymbolResult(
                [
                    new ChangedSymbol(
                        new Symbol(
                            SymbolType::Method,
                            'updateStatus',
                            $changed,
                            'src/Services/ReservationService.php',
                            30,
                            40,
                            'App\\Services\\ReservationService',
                            'public',
                            false,
                        ),
                        [35],
                    ),
                ],
                [],
                [],
            ),
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
                    RiskSeverity::Warning,
                    'Large blast radius',
                    '10 symbols may be affected by this change.',
                    10,
                    ['affected_symbols' => 10],
                ),
                new RiskFactor(
                    RiskFactor::CODE_DEEP_IMPACT,
                    RiskSeverity::Warning,
                    'Deep dependency chain',
                    'The change can reach impacted symbols up to 3 dependency levels away.',
                    3,
                    ['max_depth' => 3],
                ),
                new RiskFactor(
                    RiskFactor::CODE_HISTORICAL_CHURN,
                    RiskSeverity::Info,
                    'Historical churn',
                    'ReservationService.php has changed 18 times in Git history.',
                    18,
                    [
                        'max_commit_count' => 18,
                        'affected_files' => [
                            ['file' => 'src/Services/ReservationService.php', 'commit_count' => 18],
                        ],
                    ],
                ),
            ]),
            riskScore: new RiskScoreResult(RiskScore::of(67), RiskLevel::High, []),
            affectedFlows: AffectedFlowResult::fromFlows([
                new AffectedFlow(
                    $changed,
                    FlowType::CallChain,
                    [
                        new FlowNode('App\\Services\\PaymentService::validate', 1, true),
                        new FlowNode('App\\Repositories\\ReservationRepository::save', 2, true),
                    ],
                    [
                        new GraphEdge($changed, 'App\\Services\\PaymentService::validate', DependencyType::MethodCall, [35], 1),
                    ],
                    2,
                ),
                new AffectedFlow(
                    $changed,
                    FlowType::CallChain,
                    [
                        new FlowNode('App\\Services\\NotificationService::send', 1, true),
                    ],
                    [
                        new GraphEdge($changed, 'App\\Services\\NotificationService::send', DependencyType::MethodCall, [36], 1),
                    ],
                    1,
                ),
            ]),
            churn: new ChurnResult([
                new FileChurn('src/Services/ReservationService.php', 18, 742, 391, 5, null),
                FileChurn::none('src/NewService.php'),
            ]),
            testImpact: TestImpactResult::fromImpacts($this->testImpacts($changed)),
        ));

        $this->assertSame(
            <<<'TEXT'
<!-- ripple-analysis -->
🌊 Ripple — Code Impact Analysis

Risk: 67 / High

Changed symbols
────────────────────────
ReservationService::updateStatus()

Blast radius
────────────────────────
Depth 1: 3 symbols
Depth 2: 5 symbols
Depth 3: 2 symbols

Affected flows
────────────────────────
→ NotificationService
→ PaymentService
→ ReservationRepository

Test impact
────────────────────────
✓ 3 direct tests
⚠ 2 indirect tests

Historical churn
────────────────────────
ReservationService.php
18 commits

⚠ Risk factors
────────────────────────
High fan-in
Large blast radius
Deep dependency chain

See full analysis in the workflow artifact.
TEXT,
            $output,
        );
    }

    public function testEmptySuccessfulAnalysisUsesNonePlaceholders(): void
    {
        $output = (new PullRequestCommentFormatter())->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
        ));

        $this->assertStringContainsString("Risk: 0 / Low\n", $output);
        $this->assertStringStartsWith("<!-- ripple-analysis -->\n", $output);
        $this->assertStringContainsString("Changed symbols\n────────────────────────\nNone\n", $output);
        $this->assertStringContainsString("Blast radius\n────────────────────────\nNone\n", $output);
        $this->assertStringContainsString("Affected flows\n────────────────────────\nNone\n", $output);
        $this->assertStringContainsString("Test impact\n────────────────────────\nNone\n", $output);
        $this->assertStringContainsString("Historical churn\n────────────────────────\nNone\n", $output);
        $this->assertStringContainsString("⚠ Risk factors\n────────────────────────\nNone\n", $output);
    }

    public function testSummarizesLongSymbolAndFlowLists(): void
    {
        $changed = [];
        for ($i = 0; $i < 10; $i++) {
            $id = 'App\\Symbol' . $i;
            $changed[] = new ChangedSymbol(
                new Symbol(SymbolType::Class_, 'Symbol' . $i, $id, 'src/S.php', 1, 2, null, 'public', false),
                [1],
            );
        }

        $flows = [];
        for ($i = 0; $i < 10; $i++) {
            $id = 'App\\Flow' . $i;
            $flows[] = new AffectedFlow(
                'App\\Origin',
                FlowType::CallChain,
                [new FlowNode($id, 1, true)],
                [],
                1,
            );
        }

        $output = (new PullRequestCommentFormatter())->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            changedSymbols: new ChangedSymbolResult($changed, [], []),
            affectedFlows: AffectedFlowResult::fromFlows($flows),
        ));

        $this->assertStringContainsString("Symbol0\nSymbol1\nSymbol2\nSymbol3\nSymbol4\nSymbol5\nSymbol6\nSymbol7\n… and 2 more\n", $output);
        $this->assertStringNotContainsString('Symbol8', $output);
        $this->assertStringNotContainsString('Symbol9', $output);
        $this->assertStringContainsString("→ Flow0\n→ Flow1\n→ Flow2\n→ Flow3\n→ Flow4\n→ Flow5\n→ Flow6\n→ Flow7\n… and 2 more\n", $output);
        $this->assertStringNotContainsString('Flow8', $output);
        $this->assertStringNotContainsString('<?php', $output);
        $this->assertStringNotContainsString('Dependency graph', $output);
    }

    public function testOmitsChurnBelowTheHistoricalThreshold(): void
    {
        $output = (new PullRequestCommentFormatter())->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            churn: new ChurnResult([
                new FileChurn('src/Quiet.php', 9, 1, 1, 1, null),
                FileChurn::none('src/New.php'),
            ]),
        ));

        $this->assertStringContainsString("Historical churn\n────────────────────────\nNone\n", $output);
        $this->assertStringNotContainsString('Quiet.php', $output);
    }

    public function testOptionalGeneratedExplanationIsAppendedWithoutCallingAI(): void
    {
        $output = (new PullRequestCommentFormatter())->format(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
            new AIPrExplanation('This change may affect the reservation update flow.', true),
        );

        $this->assertStringContainsString("AI explanation\n────────────────────────\nThis change may affect the reservation update flow.\n", $output);
        $this->assertStringContainsString('See full analysis in the workflow artifact.', $output);
    }

    public function testMissingOrEmptyExplanationDoesNotAddAnAISection(): void
    {
        $formatter = new PullRequestCommentFormatter();
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));

        $this->assertStringNotContainsString('AI explanation', $formatter->format($result));
        $this->assertStringNotContainsString('AI explanation', $formatter->format($result, AIPrExplanation::none()));
    }

    public function testRiskExplanationAppearsOnlyWhenGenerated(): void
    {
        $formatter = new PullRequestCommentFormatter();
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));
        $withRisk = $formatter->format(
            $result,
            null,
            new AIRiskExplanation('Ripple identified a high fan-in dependency and a large blast radius.', true),
        );

        $this->assertStringContainsString("Risk: 0 / Low\n\nWhy this risk?\nRipple identified a high fan-in dependency and a large blast radius.\n", $withRisk);
        $this->assertStringNotContainsString('Why this risk?', $formatter->format($result));
        $this->assertStringNotContainsString('Why this risk?', $formatter->format($result, null, AIRiskExplanation::none()));
    }

    public function testFormatterDoesNotCallAnAIProvider(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Reporting/PullRequestCommentFormatter.php');

        $this->assertStringNotContainsString('AIProvider', $source);
        $this->assertStringNotContainsString('AIPrExplanationService', $source);
        $this->assertStringNotContainsString('AIRiskExplanationService', $source);
    }

    public function testFailedAnalysisDoesNotInventARiskScore(): void
    {
        $output = (new PullRequestCommentFormatter())->format(new AnalysisResult(
            status: 'error',
            message: "Ripple could not analyze this directory:\nnot a Git repository.",
        ));

        $this->assertSame(
            <<<'TEXT'
<!-- ripple-analysis -->
🌊 Ripple — Code Impact Analysis

Analysis did not complete successfully.

Ripple could not analyze this directory:
not a Git repository.

See full analysis in the workflow artifact.
TEXT,
            $output,
        );
        $this->assertStringNotContainsString('Risk:', $output);
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

    /**
     * @return list<TestImpact>
     */
    private function testImpacts(string $changed): array
    {
        $impacts = [];
        for ($i = 1; $i <= 3; $i++) {
            $impacts[] = new TestImpact(
                $changed,
                $this->testSymbol('Direct' . $i),
                1,
                TestImpactType::Direct,
            );
        }
        for ($i = 1; $i <= 2; $i++) {
            $impacts[] = new TestImpact(
                $changed,
                $this->testSymbol('Indirect' . $i),
                3,
                TestImpactType::Indirect,
            );
        }

        return $impacts;
    }

    private function testSymbol(string $name): TestSymbol
    {
        return new TestSymbol(
            'Tests\\Unit\\' . $name . '::testIt',
            TestSymbolType::Method,
            'tests/Unit/' . $name . '.php',
            'Tests\\Unit\\' . $name,
            'testIt',
            10,
            14,
            'Tests\\Unit\\' . $name,
        );
    }
}
