<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Explanation\AIPrExplanationRequestBuilder;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Flow\AffectedFlow;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Flow\FlowNode;
use Ripple\Analysis\Flow\FlowType;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskLevel;
use Ripple\Analysis\Risk\RiskScore;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\SemanticImpactResult;
use Ripple\Analysis\Tests\TestImpact;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;
use Ripple\Analysis\Tests\TestSymbol;
use Ripple\Analysis\Tests\TestSymbolType;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\DiffResult;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;

final class AIPrExplanationRequestBuilderTest extends TestCase
{
    public function testBuildsACompactSummaryFromDeterministicFacts(): void
    {
        $changed = 'App\\Services\\ReservationService::updateStatus';
        $request = (new AIPrExplanationRequestBuilder())->build($this->analysisResult($changed));
        $input = $request->input;

        $this->assertSame(AIPrExplanationRequestBuilder::INSTRUCTIONS, $request->instructions);
        $this->assertStringContainsString('You are explaining a code impact analysis', $request->instructions);
        $this->assertStringContainsString('Do not recalculate the risk score', $request->instructions);
        $this->assertStringContainsString("Changed files:\n  src/Services/ReservationService.php", $input);
        $this->assertStringContainsString("Changed symbols:\n  {$changed}", $input);
        $this->assertStringContainsString("Risk:\n  67 / high", $input);
        $this->assertStringContainsString('high_fan_in', $input);
        $this->assertStringContainsString('large_blast_radius', $input);
        $this->assertStringContainsString('historical_churn', $input);
        $this->assertStringContainsString("Blast radius:\n  symbols: 10\n  depth 1: 3\n  depth 2: 5\n  depth 3: 2", $input);
        $this->assertStringContainsString('App\\Services\\PaymentService::validate', $input);
        $this->assertStringContainsString('api_entrypoint App\\Http\\Controllers\\ReservationController::update', $input);
        $this->assertStringContainsString('database_write App\\Repositories\\PaymentRepository::update', $input);
        $this->assertStringContainsString("Test impact:\n  direct: 2\n  indirect: 4", $input);
        $this->assertStringContainsString('src/Services/ReservationService.php: 18 commits', $input);
        $this->assertStringNotContainsString('<?php', $input);
        $this->assertStringNotContainsString('public function', $input);
        $this->assertStringNotContainsString('Dependency graph', $input);
        $this->assertStringNotContainsString('class ReservationService', $input);
    }

    public function testEmptyResultUsesNonePlaceholdersWithoutGuessing(): void
    {
        $input = (new AIPrExplanationRequestBuilder())->build(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
        ))->input;

        $this->assertStringContainsString("Changed files:\n  none", $input);
        $this->assertStringContainsString("Changed symbols:\n  none", $input);
        $this->assertStringContainsString("Risk:\n  0 / low", $input);
        $this->assertStringContainsString("Risk factors:\n  none", $input);
        $this->assertStringContainsString("Blast radius:\n  none", $input);
        $this->assertStringContainsString("Affected flows:\n  none", $input);
        $this->assertStringContainsString("Semantic impact:\n  none", $input);
        $this->assertStringContainsString("Test impact:\n  none", $input);
        $this->assertStringContainsString("Historical churn:\n  none", $input);
    }

    private function analysisResult(string $changed): AnalysisResult
    {
        return new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile('src/Services/ReservationService.php', ChangeType::Modified, [35], [34]),
            ]),
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
                    ['count' => 10],
                ),
                new RiskFactor(
                    RiskFactor::CODE_HISTORICAL_CHURN,
                    RiskSeverity::Info,
                    'Historical churn',
                    'ReservationService.php has changed 18 times in Git history.',
                    18,
                    ['max_commit_count' => 18],
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
                    [],
                    2,
                ),
            ]),
            semanticImpact: SemanticImpactResult::fromAnnotations(
                [
                    new SemanticAnnotation(
                        'App\\Http\\Controllers\\ReservationController::update',
                        FlowSemanticType::ApiEntrypoint,
                    ),
                ],
                [
                    new SemanticAnnotation(
                        'App\\Repositories\\PaymentRepository::update',
                        FlowSemanticType::DatabaseWrite,
                    ),
                ],
            ),
            churn: new ChurnResult([
                new FileChurn('src/Services/ReservationService.php', 18, 742, 391, 5, null),
            ]),
            testImpact: TestImpactResult::fromImpacts($this->testImpacts($changed)),
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

    /**
     * @return list<TestImpact>
     */
    private function testImpacts(string $changed): array
    {
        $impacts = [];
        for ($i = 1; $i <= 2; $i++) {
            $impacts[] = new TestImpact($changed, $this->testSymbol('Direct' . $i), 1, TestImpactType::Direct);
        }
        for ($i = 1; $i <= 4; $i++) {
            $impacts[] = new TestImpact($changed, $this->testSymbol('Indirect' . $i), 3, TestImpactType::Indirect);
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
            20,
            'Tests\\Unit\\' . $name,
        );
    }
}
