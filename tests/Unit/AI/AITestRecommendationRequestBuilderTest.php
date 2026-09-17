<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Testing\AITestRecommendationRequestBuilder;
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
use Ripple\Analysis\Impact\BlastRadiusOrigin;
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
use Ripple\Git\DiffResult;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;

final class AITestRecommendationRequestBuilderTest extends TestCase
{
    public function testIncludesDeterministicFactsWithoutInventingTests(): void
    {
        $changed = 'App\\Services\\ReservationService::updateStatus';
        $request = (new AITestRecommendationRequestBuilder())->build($this->analysisResult($changed));
        $input = $request->input;

        $this->assertSame(AITestRecommendationRequestBuilder::INSTRUCTIONS, $request->instructions);
        $this->assertStringContainsString('You are generating test recommendations from deterministic Ripple analysis.', $request->instructions);
        $this->assertStringContainsString('Never invent test names, classes, files, or methods.', $request->instructions);
        $this->assertStringContainsString('Do not claim a test will definitely fail.', $request->instructions);
        $this->assertStringContainsString('Do not calculate or modify Ripple\'s risk score.', $request->instructions);
        $this->assertStringContainsString(
            $changed . ' type=method file=src/Services/ReservationService.php lines=35',
            $input,
        );
        $this->assertStringContainsString('directly_impacted_tests:', $input);
        $this->assertStringContainsString('indirectly_impacted_tests:', $input);
        $this->assertStringContainsString(
            'Tests\\Unit\\ReservationServiceTest::testUpdateStatus type=method file=tests/Unit/ReservationServiceTest.php production_symbol=' . $changed . ' depth=1 impact=direct',
            $input,
        );
        $this->assertStringContainsString(
            'Tests\\Unit\\PaymentServiceTest::testValidate type=method file=tests/Unit/PaymentServiceTest.php production_symbol=' . $changed . ' depth=3 impact=indirect',
            $input,
        );
        $this->assertStringContainsString(
            'App\\Services\\PaymentService::validate depth=1 origin=' . $changed . ' origin_depth=1 edge=method_call ' . $changed . '->App\\Services\\PaymentService::validate',
            $input,
        );
        $this->assertStringContainsString(
            $changed . ' type=call_chain depth=2 nodes=App\\Services\\PaymentService::validate -> App\\Repositories\\ReservationRepository::save',
            $input,
        );
        $this->assertStringContainsString("Risk:\n  score=67\n  level=high", $input);
        $this->assertStringContainsString('high_fan_in severity=warning', $input);
        $this->assertStringContainsString('large_blast_radius severity=high', $input);
        $this->assertStringContainsString('historical_churn severity=warning', $input);
        $this->assertStringContainsString('blast_radius api_entrypoint App\\Http\\Controllers\\ReservationController::update', $input);
        $this->assertStringContainsString('affected_flows database_write App\\Repositories\\PaymentRepository::update', $input);
        $this->assertStringContainsString('affected_flows queue App\\Jobs\\NotifyReservationUpdated::handle', $input);
        $this->assertStringContainsString('src/Services/ReservationService.php commits=18', $input);
        $this->assertStringNotContainsString('MissingCoverageTest', $input);
        $this->assertStringNotContainsString('InventedTest', $input);
        $this->assertStringNotContainsString('<?php', $input);
        $this->assertStringNotContainsString('public function', $input);
        $this->assertStringNotContainsString('class ReservationService', $input);
        $this->assertStringNotContainsString('Dependency graph', $input);
        $this->assertStringNotContainsString('coverage', $input);
        $this->assertSame(1, substr_count($input, 'score=67'));
        $this->assertSame(1, substr_count($input, 'level=high'));
    }

    public function testEmptyResultUsesNonePlaceholdersWithoutGuessing(): void
    {
        $input = (new AITestRecommendationRequestBuilder())->build(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
        ))->input;

        $this->assertStringContainsString("Changed symbols:\n  none", $input);
        $this->assertStringContainsString("Test impact:\n  none", $input);
        $this->assertStringContainsString("Blast radius:\n  none", $input);
        $this->assertStringContainsString("Affected flows:\n  none", $input);
        $this->assertStringContainsString("Risk:\n  score=0\n  level=low", $input);
        $this->assertStringContainsString("Semantic impact:\n  none", $input);
        $this->assertStringContainsString("Historical churn:\n  none", $input);
        $this->assertStringNotContainsString('ReservationServiceTest', $input);
    }

    public function testBuilderDoesNotRecalculateImpactOrInspectSource(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AI/Testing/AITestRecommendationRequestBuilder.php',
        );

        $this->assertStringNotContainsString('TestImpactAnalyzer', $source);
        $this->assertStringNotContainsString('TransitiveImpactAnalyzer', $source);
        $this->assertStringNotContainsString('RiskScoreCalculator', $source);
        $this->assertStringNotContainsString('GitHistoryAnalyzer', $source);
        $this->assertStringNotContainsString('DependencyGraphBuilder', $source);
        $this->assertStringNotContainsString('file_get_contents', $source);
        $this->assertStringNotContainsString('phpunit', $source);
        $this->assertStringNotContainsString('ReservationServiceTest', $source);
    }

    private function analysisResult(string $changed): AnalysisResult
    {
        return new AnalysisResult(
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
            blastRadius: TransitiveImpactResult::fromEntries([
                new BlastRadiusEntry(
                    'App\\Services\\PaymentService::validate',
                    1,
                    [
                        new BlastRadiusOrigin(
                            $changed,
                            1,
                            new GraphEdge(
                                $changed,
                                'App\\Services\\PaymentService::validate',
                                DependencyType::MethodCall,
                                [35],
                                1,
                            ),
                        ),
                    ],
                ),
            ]),
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
                    new SemanticAnnotation(
                        'App\\Jobs\\NotifyReservationUpdated::handle',
                        FlowSemanticType::Queue,
                    ),
                ],
            ),
            churn: new ChurnResult([
                new FileChurn('src/Services/ReservationService.php', 18, 742, 391, 5, null),
            ]),
            testImpact: TestImpactResult::fromImpacts([
                new TestImpact(
                    $changed,
                    $this->testSymbol(
                        'Tests\\Unit\\ReservationServiceTest::testUpdateStatus',
                        'tests/Unit/ReservationServiceTest.php',
                        'Tests\\Unit\\ReservationServiceTest',
                        'testUpdateStatus',
                    ),
                    1,
                    TestImpactType::Direct,
                ),
                new TestImpact(
                    $changed,
                    $this->testSymbol(
                        'Tests\\Unit\\PaymentServiceTest::testValidate',
                        'tests/Unit/PaymentServiceTest.php',
                        'Tests\\Unit\\PaymentServiceTest',
                        'testValidate',
                    ),
                    3,
                    TestImpactType::Indirect,
                ),
            ]),
        );
    }

    private function testSymbol(string $id, string $file, string $class, string $method): TestSymbol
    {
        return new TestSymbol(
            $id,
            TestSymbolType::Method,
            $file,
            $class,
            $method,
            10,
            20,
            $class,
        );
    }
}
