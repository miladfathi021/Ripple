<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbol;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\ChangedSymbols\UnmappedFileLines;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Flow\AffectedFlow;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Flow\FlowNode;
use Ripple\Analysis\Flow\FlowType;
use Ripple\Analysis\Graph\DependencyGraphBuilder;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\BlastRadiusOrigin;
use Ripple\Analysis\Impact\DirectImpact;
use Ripple\Analysis\Impact\DirectImpactResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Index\RepositoryIndex;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreCalculator;
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
use Ripple\Reporting\JsonReportFormatter;
use Ripple\Reporting\TextReportFormatter;
use DateTimeImmutable;

final class ReportFormatterTest extends TestCase
{
    public function testTextOutputSummarizesChangedFiles(): void
    {
        $output = (new TextReportFormatter())->format($this->successfulResult());

        $this->assertSame(
            <<<'TEXT'
🌊 Ripple

Repository index:
  PHP files: 0
  Symbols: 0
  Dependencies: 0

Changed files: 2

M src/ReservationService.php
  +2 -1

A src/NewService.php
  +48

Changed symbols:
  None

Direct impact:
  None

Blast radius:
  None

Test impact:
  None

Risk score:
  0/100 — Low

Dependencies:
  None

Dependency graph:
  Nodes: 0
  Edges: 0

Reverse dependencies:
  None
TEXT,
            $output,
        );
    }

    public function testTextOutputListsChangedSymbolsAndUnmappedLines(): void
    {
        $output = (new TextReportFormatter())->format($this->resultWithChangedSymbols());

        $this->assertSame(
            <<<'TEXT'
🌊 Ripple

Repository index:
  PHP files: 0
  Symbols: 0
  Dependencies: 0

Changed files: 1

M src/Services/ReservationService.php
  +5

Changed symbols:

  App\Services\ReservationService::updateStatus()
    lines: 35, 36, 40

Unmapped changed lines:
  src/Services/ReservationService.php: 3, 4

Direct impact:
  None

Blast radius:
  None

Test impact:
  None

Risk score:
  0/100 — Low

Dependencies:
  None

Dependency graph:
  Nodes: 0
  Edges: 0

Reverse dependencies:
  None
TEXT,
            $output,
        );
    }

    public function testJsonOutputExposesStructuredDiffData(): void
    {
        $output = (new JsonReportFormatter())->format($this->successfulResult());
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'status' => 'ok',
                'diff' => [
                    'files' => [
                        [
                            'path' => 'src/ReservationService.php',
                            'change_type' => 'modified',
                            'added_lines' => [11, 12],
                            'deleted_lines' => [10],
                        ],
                        [
                            'path' => 'src/NewService.php',
                            'change_type' => 'added',
                            'added_lines' => range(1, 48),
                            'deleted_lines' => [],
                        ],
                    ],
                ],
                'changed_symbols' => [],
                'direct_impact' => [],
                'blast_radius' => [],
                'test_impact' => [],
                'risk_factors' => [],
                'risk_score' => [
                    'score' => 0,
                    'level' => 'low',
                    'contributions' => [],
                ],
                'affected_flows' => [],
                'semantic_impact' => [
                    'blast_radius' => [],
                    'affected_flows' => [],
                ],
                'churn' => [],
                'unmapped_lines' => [],
                'unmapped_deleted_lines' => [],
                'dependencies' => [],
                'graph' => [
                    'nodes' => [],
                    'edges' => [],
                ],
                'reverse_graph' => [
                    'dependents' => [],
                ],
                'project_index' => [
                    'php_files' => 0,
                    'symbols' => 0,
                    'dependencies' => 0,
                ],
            ],
            $payload,
        );
    }

    public function testJsonOutputIncludesChangedSymbols(): void
    {
        $payload = json_decode(
            (new JsonReportFormatter())->format($this->resultWithChangedSymbols()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(
            [
                [
                    'type' => 'method',
                    'name' => 'updateStatus',
                    'fully_qualified_name' => 'App\\Services\\ReservationService::updateStatus',
                    'file' => 'src/Services/ReservationService.php',
                    'start_line' => 30,
                    'end_line' => 40,
                    'parent' => 'App\\Services\\ReservationService',
                    'visibility' => 'public',
                    'is_static' => false,
                    'changed_lines' => [35, 36, 40],
                ],
            ],
            $payload['changed_symbols'],
        );
        $this->assertSame([], $payload['direct_impact']);
        $this->assertSame([], $payload['blast_radius']);
        $this->assertSame([], $payload['test_impact']);
        $this->assertSame([], $payload['risk_factors']);
        $this->assertSame(
            [
                'score' => 0,
                'level' => 'low',
                'contributions' => [],
            ],
            $payload['risk_score'],
        );
        $this->assertSame([], $payload['affected_flows']);
        $this->assertSame(
            [
                'blast_radius' => [],
                'affected_flows' => [],
            ],
            $payload['semantic_impact'],
        );
        $this->assertSame([], $payload['churn']);
        $this->assertSame(
            [
                [
                    'file' => 'src/Services/ReservationService.php',
                    'lines' => [3, 4],
                ],
            ],
            $payload['unmapped_lines'],
        );
        $this->assertSame([], $payload['dependencies']);
        $this->assertSame(['nodes' => [], 'edges' => []], $payload['graph']);
        $this->assertSame(['dependents' => []], $payload['reverse_graph']);
        $this->assertSame(
            ['php_files' => 0, 'symbols' => 0, 'dependencies' => 0],
            $payload['project_index'],
        );
    }

    public function testTextAndJsonOutputIncludeDependencies(): void
    {
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile('src/Service.php', ChangeType::Modified, [35], []),
            ]),
            dependencies: new DependencyResult([
                new Dependency(
                    'App\\Services\\ReservationService::updateStatus',
                    'App\\Services\\PaymentService::validate',
                    DependencyType::MethodCall,
                    [35],
                    1,
                ),
            ]),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString('Dependencies:', $text);
        $this->assertStringContainsString('App\\Services\\ReservationService::updateStatus', $text);
        $this->assertStringContainsString('→ App\\Services\\PaymentService::validate', $text);
        $this->assertStringContainsString('type: method_call', $text);
        $this->assertStringContainsString('line: 35', $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'source' => 'App\\Services\\ReservationService::updateStatus',
                    'target' => 'App\\Services\\PaymentService::validate',
                    'type' => 'method_call',
                    'line' => 35,
                    'lines' => [35],
                    'occurrences' => 1,
                ],
            ],
            $payload['dependencies'],
        );
        $this->assertSame(['nodes' => [], 'edges' => []], $payload['graph']);
        $this->assertSame(['dependents' => []], $payload['reverse_graph']);
        $this->assertSame(
            ['php_files' => 0, 'symbols' => 0, 'dependencies' => 0],
            $payload['project_index'],
        );
    }

    public function testTextAndJsonOutputIncludeTheDependencyGraph(): void
    {
        $source = 'App\\Services\\ReservationService::updateStatus';
        $target = 'App\\Services\\PaymentService::validate';
        $dependencies = new DependencyResult([
            new Dependency($source, $target, DependencyType::MethodCall, [35], 1),
            new Dependency($source, 'App\\Services\\PaymentService', DependencyType::ParameterType, [12], 1),
        ]);
        $symbol = new Symbol(
            SymbolType::Method,
            'updateStatus',
            $source,
            'src/Services/ReservationService.php',
            30,
            40,
            'App\\Services\\ReservationService',
            'public',
            false,
        );
        $graph = (new DependencyGraphBuilder())->build($dependencies, [$symbol]);
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile('src/Services/ReservationService.php', ChangeType::Modified, [35], []),
            ]),
            dependencies: $dependencies,
            graph: $graph,
            reverseGraph: (new ReverseDependencyGraphBuilder())->build($graph),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString("Dependency graph:\n  Nodes: 3\n  Edges: 2", $text);
        $this->assertStringContainsString($source, $text);
        $this->assertStringContainsString('→ App\\Services\\PaymentService [parameter_type]', $text);
        $this->assertStringContainsString('→ App\\Services\\PaymentService::validate [method_call]', $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'id' => 'App\\Services\\PaymentService',
                    'type' => null,
                    'name' => 'PaymentService',
                    'fully_qualified_name' => 'App\\Services\\PaymentService',
                    'file' => null,
                    'known' => false,
                ],
                [
                    'id' => 'App\\Services\\PaymentService::validate',
                    'type' => null,
                    'name' => 'validate',
                    'fully_qualified_name' => 'App\\Services\\PaymentService::validate',
                    'file' => null,
                    'known' => false,
                ],
                [
                    'id' => $source,
                    'type' => 'method',
                    'name' => 'updateStatus',
                    'fully_qualified_name' => $source,
                    'file' => 'src/Services/ReservationService.php',
                    'known' => true,
                ],
            ],
            $payload['graph']['nodes'],
        );
        $this->assertSame(
            [
                [
                    'source' => $source,
                    'target' => 'App\\Services\\PaymentService',
                    'type' => 'parameter_type',
                    'lines' => [12],
                    'occurrences' => 1,
                ],
                [
                    'source' => $source,
                    'target' => $target,
                    'type' => 'method_call',
                    'lines' => [35],
                    'occurrences' => 1,
                ],
            ],
            $payload['graph']['edges'],
        );
        $this->assertSame($payload['dependencies'][0]['source'], $payload['graph']['edges'][1]['source']);
    }

    public function testTextAndJsonOutputIncludeDirectReverseDependencies(): void
    {
        $source = 'App\\Services\\ReservationService::updateStatus';
        $target = 'App\\Services\\PaymentService::validate';
        $dependencies = new DependencyResult([
            new Dependency($source, $target, DependencyType::MethodCall, [35], 1),
            new Dependency($source, 'App\\Services\\PaymentService', DependencyType::ParameterType, [12], 1),
        ]);
        $symbol = new Symbol(
            SymbolType::Method,
            'updateStatus',
            $source,
            'src/Services/ReservationService.php',
            30,
            40,
            'App\\Services\\ReservationService',
            'public',
            false,
        );
        $graph = (new DependencyGraphBuilder())->build($dependencies, [$symbol]);
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile('src/Services/ReservationService.php', ChangeType::Modified, [35], []),
            ]),
            graph: $graph,
            reverseGraph: (new ReverseDependencyGraphBuilder())->build($graph),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString('Reverse dependencies:', $text);
        $this->assertStringContainsString("  App\\Services\\PaymentService\n    ← {$source} [parameter_type]", $text);
        $this->assertStringContainsString("  {$target}\n    ← {$source} [method_call]", $text);
        $this->assertStringContainsString("Blast radius:\n  None", $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                'App\\Services\\PaymentService' => [
                    [
                        'source' => $source,
                        'target' => 'App\\Services\\PaymentService',
                        'type' => 'parameter_type',
                        'lines' => [12],
                        'occurrences' => 1,
                    ],
                ],
                $target => [
                    [
                        'source' => $source,
                        'target' => $target,
                        'type' => 'method_call',
                        'lines' => [35],
                        'occurrences' => 1,
                    ],
                ],
            ],
            $payload['reverse_graph']['dependents'],
        );
    }

    public function testTextAndJsonOutputIncludeDirectImpact(): void
    {
        $changed = 'App\\Services\\ReservationService::updateStatus';
        $impacted = 'App\\Services\\PaymentService::validate';
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            directImpact: DirectImpactResult::fromImpacts([
                new DirectImpact(
                    $changed,
                    $impacted,
                    new GraphEdge($impacted, $changed, DependencyType::MethodCall, [12], 1),
                ),
                new DirectImpact(
                    $changed,
                    $impacted,
                    new GraphEdge($impacted, $changed, DependencyType::ParameterType, [8], 1),
                ),
            ]),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString("Direct impact:\n\n  {$impacted}()\n    ← method_call\n    ← parameter_type", $text);
        $this->assertStringContainsString("Blast radius:\n  None", $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'changed_symbol' => $changed,
                    'impacted_symbol' => $impacted,
                    'dependency_type' => 'method_call',
                    'lines' => [12],
                    'occurrences' => 1,
                ],
                [
                    'changed_symbol' => $changed,
                    'impacted_symbol' => $impacted,
                    'dependency_type' => 'parameter_type',
                    'lines' => [8],
                    'occurrences' => 1,
                ],
            ],
            $payload['direct_impact'],
        );
    }

    public function testTextAndJsonOutputIncludeBlastRadius(): void
    {
        $changed = 'ReservationService::updateStatus';
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            blastRadius: TransitiveImpactResult::fromEntries([
                new BlastRadiusEntry(
                    'PaymentService::validate',
                    1,
                    [
                        new BlastRadiusOrigin(
                            $changed,
                            1,
                            new GraphEdge(
                                'PaymentService::validate',
                                $changed,
                                DependencyType::MethodCall,
                                [6],
                                1,
                            ),
                        ),
                    ],
                ),
                new BlastRadiusEntry(
                    'AuditService::record',
                    1,
                    [
                        new BlastRadiusOrigin(
                            $changed,
                            1,
                            new GraphEdge(
                                'AuditService::record',
                                $changed,
                                DependencyType::MethodCall,
                                [6],
                                1,
                            ),
                        ),
                    ],
                ),
                new BlastRadiusEntry(
                    'PaymentRepository::update',
                    2,
                    [
                        new BlastRadiusOrigin(
                            $changed,
                            2,
                            new GraphEdge(
                                'PaymentRepository::update',
                                'PaymentService::validate',
                                DependencyType::MethodCall,
                                [12],
                                1,
                            ),
                        ),
                    ],
                ),
                new BlastRadiusEntry(
                    'NotificationService::send',
                    2,
                    [
                        new BlastRadiusOrigin(
                            $changed,
                            2,
                            new GraphEdge(
                                'NotificationService::send',
                                'PaymentService::validate',
                                DependencyType::MethodCall,
                                [8],
                                1,
                            ),
                        ),
                    ],
                ),
            ]),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Blast radius:
  Depth 1:
    AuditService::record()
    PaymentService::validate()

  Depth 2:
    NotificationService::send()
    PaymentRepository::update()
TEXT,
            $text,
        );
        $this->assertStringContainsString('Direct impact:', $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload['direct_impact']);
        $this->assertSame(
            [
                [
                    'impacted_symbol' => 'AuditService::record',
                    'depth' => 1,
                    'origins' => [
                        [
                            'changed_symbol' => $changed,
                            'depth' => 1,
                            'dependency_type' => 'method_call',
                            'lines' => [6],
                            'occurrences' => 1,
                        ],
                    ],
                ],
                [
                    'impacted_symbol' => 'PaymentService::validate',
                    'depth' => 1,
                    'origins' => [
                        [
                            'changed_symbol' => $changed,
                            'depth' => 1,
                            'dependency_type' => 'method_call',
                            'lines' => [6],
                            'occurrences' => 1,
                        ],
                    ],
                ],
                [
                    'impacted_symbol' => 'NotificationService::send',
                    'depth' => 2,
                    'origins' => [
                        [
                            'changed_symbol' => $changed,
                            'depth' => 2,
                            'dependency_type' => 'method_call',
                            'lines' => [8],
                            'occurrences' => 1,
                        ],
                    ],
                ],
                [
                    'impacted_symbol' => 'PaymentRepository::update',
                    'depth' => 2,
                    'origins' => [
                        [
                            'changed_symbol' => $changed,
                            'depth' => 2,
                            'dependency_type' => 'method_call',
                            'lines' => [12],
                            'occurrences' => 1,
                        ],
                    ],
                ],
            ],
            $payload['blast_radius'],
        );
        $this->assertSame([], $payload['risk_factors']);
        $this->assertSame(
            [
                'score' => 0,
                'level' => 'low',
                'contributions' => [],
            ],
            $payload['risk_score'],
        );
        $this->assertStringNotContainsString('Risk factors:', $text);
        $this->assertStringContainsString("Risk score:\n  0/100 — Low", $text);
    }

    public function testTextAndJsonOutputIncludeRiskFactors(): void
    {
        $riskFactors = RiskFactorResult::fromFactors([
            new RiskFactor(
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
                RiskSeverity::Info,
                'Multiple changed symbols',
                '2 symbols were changed in this analysis.',
                2,
                ['changed_symbols' => 2],
            ),
            new RiskFactor(
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskSeverity::Warning,
                'High fan-in',
                'ReservationService::updateStatus has 8 direct dependents.',
                8,
                ['symbol' => 'ReservationService::updateStatus', 'direct_dependents' => 8],
            ),
            new RiskFactor(
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskSeverity::High,
                'Large blast radius',
                '12 symbols may be affected by this change.',
                12,
                ['affected_symbols' => 12],
            ),
        ]);
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            riskFactors: $riskFactors,
            riskScore: (new RiskScoreCalculator())->calculate($riskFactors),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Risk score:
  40/100 — Medium

Risk factors:

⚠ High fan-in
   ReservationService::updateStatus has 8 direct dependents.

⚠ Large blast radius
   12 symbols may be affected by this change.

ℹ Multiple changed symbols
   2 symbols were changed in this analysis.
TEXT,
            $text,
        );

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'code' => 'high_fan_in',
                    'severity' => 'warning',
                    'title' => 'High fan-in',
                    'description' => 'ReservationService::updateStatus has 8 direct dependents.',
                    'value' => 8,
                    'evidence' => [
                        'symbol' => 'ReservationService::updateStatus',
                        'direct_dependents' => 8,
                    ],
                ],
                [
                    'code' => 'large_blast_radius',
                    'severity' => 'high',
                    'title' => 'Large blast radius',
                    'description' => '12 symbols may be affected by this change.',
                    'value' => 12,
                    'evidence' => [
                        'affected_symbols' => 12,
                    ],
                ],
                [
                    'code' => 'multiple_changed_symbols',
                    'severity' => 'info',
                    'title' => 'Multiple changed symbols',
                    'description' => '2 symbols were changed in this analysis.',
                    'value' => 2,
                    'evidence' => [
                        'changed_symbols' => 2,
                    ],
                ],
            ],
            $payload['risk_factors'],
        );
        $this->assertSame(
            [
                'score' => 40,
                'level' => 'medium',
                'contributions' => [
                    [
                        'code' => 'high_fan_in',
                        'title' => 'High fan-in',
                        'severity' => 'warning',
                        'max_weight' => 20,
                        'contribution' => 15,
                    ],
                    [
                        'code' => 'large_blast_radius',
                        'title' => 'Large blast radius',
                        'severity' => 'high',
                        'max_weight' => 20,
                        'contribution' => 20,
                    ],
                    [
                        'code' => 'multiple_changed_symbols',
                        'title' => 'Multiple changed symbols',
                        'severity' => 'info',
                        'max_weight' => 10,
                        'contribution' => 5,
                    ],
                ],
            ],
            $payload['risk_score'],
        );
    }

    public function testTextAndJsonOutputIncludeHistoricalChurn(): void
    {
        $riskFactors = RiskFactorResult::fromFactors([
            new RiskFactor(
                RiskFactor::CODE_HISTORICAL_CHURN,
                RiskSeverity::Warning,
                'Historical churn',
                "Frequently changed files:\n  src/Services/ReservationService.php — 31 commits\n  src/Services/PaymentService.php — 12 commits",
                31,
                [
                    'max_commit_count' => 31,
                    'affected_files' => [
                        ['file' => 'src/Services/ReservationService.php', 'commit_count' => 31],
                        ['file' => 'src/Services/PaymentService.php', 'commit_count' => 12],
                    ],
                ],
            ),
        ]);
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            riskFactors: $riskFactors,
            riskScore: (new RiskScoreCalculator())->calculate($riskFactors),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Risk score:
  19/100 — Low

Risk factors:

⚠ Historical churn
   Frequently changed files:
     src/Services/ReservationService.php — 31 commits
     src/Services/PaymentService.php — 12 commits
TEXT,
            $text,
        );
        $this->assertStringNotContainsString('likely to break', $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'code' => 'historical_churn',
                    'severity' => 'warning',
                    'title' => 'Historical churn',
                    'description' => "Frequently changed files:\n  src/Services/ReservationService.php — 31 commits\n  src/Services/PaymentService.php — 12 commits",
                    'value' => 31,
                    'evidence' => [
                        'max_commit_count' => 31,
                        'affected_files' => [
                            ['file' => 'src/Services/ReservationService.php', 'commit_count' => 31],
                            ['file' => 'src/Services/PaymentService.php', 'commit_count' => 12],
                        ],
                    ],
                ],
            ],
            $payload['risk_factors'],
        );
        $this->assertSame(19, $payload['risk_score']['score']);
        $this->assertSame(25, $payload['risk_score']['contributions'][0]['max_weight']);
        $this->assertSame(19, $payload['risk_score']['contributions'][0]['contribution']);
    }

    public function testTextAndJsonOutputIncludeAffectedFlows(): void
    {
        $changed = 'ReservationService::updateStatus';
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            affectedFlows: AffectedFlowResult::fromFlows([
                new AffectedFlow(
                    $changed,
                    FlowType::CallChain,
                    [
                        new FlowNode('PaymentService::validate', 1, true),
                        new FlowNode('PaymentRepository::update', 2, true),
                    ],
                    [
                        new GraphEdge($changed, 'PaymentService::validate', DependencyType::MethodCall, [35], 1),
                        new GraphEdge('PaymentService::validate', 'PaymentRepository::update', DependencyType::MethodCall, [18], 1),
                    ],
                    2,
                ),
                new AffectedFlow(
                    $changed,
                    FlowType::ConstructionChain,
                    [
                        new FlowNode('StripeClient', 1, false),
                    ],
                    [
                        new GraphEdge($changed, 'StripeClient', DependencyType::ConstructorCall, [40], 1),
                    ],
                    1,
                ),
            ]),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Affected flows:

  [construction_chain]
    ReservationService::updateStatus()
      → StripeClient

  [call_chain]
    ReservationService::updateStatus()
      → PaymentService::validate()
      → PaymentRepository::update()
TEXT,
            $text,
        );
        $this->assertStringNotContainsString('Database', $text);
        $this->assertStringNotContainsString('External API', $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'changed_symbol' => $changed,
                    'type' => 'construction_chain',
                    'depth' => 1,
                    'nodes' => [
                        ['id' => 'StripeClient', 'depth' => 1],
                    ],
                    'edges' => [
                        [
                            'source' => $changed,
                            'target' => 'StripeClient',
                            'dependency_type' => 'constructor_call',
                            'lines' => [40],
                            'occurrences' => 1,
                        ],
                    ],
                ],
                [
                    'changed_symbol' => $changed,
                    'type' => 'call_chain',
                    'depth' => 2,
                    'nodes' => [
                        ['id' => 'PaymentService::validate', 'depth' => 1],
                        ['id' => 'PaymentRepository::update', 'depth' => 2],
                    ],
                    'edges' => [
                        [
                            'source' => $changed,
                            'target' => 'PaymentService::validate',
                            'dependency_type' => 'method_call',
                            'lines' => [35],
                            'occurrences' => 1,
                        ],
                        [
                            'source' => 'PaymentService::validate',
                            'target' => 'PaymentRepository::update',
                            'dependency_type' => 'method_call',
                            'lines' => [18],
                            'occurrences' => 1,
                        ],
                    ],
                ],
            ],
            $payload['affected_flows'],
        );
    }

    public function testTextAndJsonOutputIncludeSemanticImpact(): void
    {
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
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
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Semantic impact:
  Blast radius:
    [api_entrypoint]
      App\Http\Controllers\ReservationController::update()

  Affected flows:
    [database_write]
      App\Repositories\PaymentRepository::update()
TEXT,
            $text,
        );
        $this->assertStringNotContainsString('Queue', $text);
        $this->assertStringNotContainsString('Authentication', $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                'blast_radius' => [
                    [
                        'symbol' => 'App\\Http\\Controllers\\ReservationController::update',
                        'type' => 'api_entrypoint',
                    ],
                ],
                'affected_flows' => [
                    [
                        'symbol' => 'App\\Repositories\\PaymentRepository::update',
                        'type' => 'database_write',
                    ],
                ],
            ],
            $payload['semantic_impact'],
        );
    }

    public function testTextOmitsEmptySemanticImpactSection(): void
    {
        $text = (new TextReportFormatter())->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            semanticImpact: SemanticImpactResult::empty(),
        ));

        $this->assertStringNotContainsString('Semantic impact:', $text);
    }

    public function testTextAndJsonOutputIncludeGitHistory(): void
    {
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile('src/Services/ReservationService.php', ChangeType::Modified, [1], []),
            ]),
            churn: ChurnResult::fromFiles([
                new FileChurn(
                    'src/Services/ReservationService.php',
                    18,
                    742,
                    391,
                    5,
                    new DateTimeImmutable('2026-09-14T13:42:10+00:00'),
                ),
                FileChurn::none('src/NewService.php'),
            ]),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Git history:
  src/NewService.php
    commits: 0
    lines added: 0
    lines deleted: 0
    contributors: 0
    last changed: none
  src/Services/ReservationService.php
    commits: 18
    lines added: 742
    lines deleted: 391
    contributors: 5
    last changed: 2026-09-14 13:42:10 UTC
TEXT,
            $text,
        );

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'file' => 'src/NewService.php',
                    'commit_count' => 0,
                    'lines_added' => 0,
                    'lines_deleted' => 0,
                    'contributors_count' => 0,
                    'last_changed_at' => null,
                ],
                [
                    'file' => 'src/Services/ReservationService.php',
                    'commit_count' => 18,
                    'lines_added' => 742,
                    'lines_deleted' => 391,
                    'contributors_count' => 5,
                    'last_changed_at' => '2026-09-14T13:42:10+00:00',
                ],
            ],
            $payload['churn'],
        );
    }

    public function testTextOmitsEmptyGitHistorySection(): void
    {
        $text = (new TextReportFormatter())->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            churn: ChurnResult::empty(),
        ));

        $this->assertStringNotContainsString('Git history:', $text);
    }

    public function testTextAndJsonOutputIncludeTestImpact(): void
    {
        $direct = new TestSymbol(
            'Tests\\Unit\\ReservationServiceTest::testUpdateStatus',
            TestSymbolType::Method,
            'tests/Unit/ReservationServiceTest.php',
            'Tests\\Unit\\ReservationServiceTest',
            'testUpdateStatus',
            10,
            14,
            'Tests\\Unit\\ReservationServiceTest',
        );
        $indirect = new TestSymbol(
            'Tests\\Feature\\PaymentTest::testReservationPayment',
            TestSymbolType::Method,
            'tests/Feature/PaymentTest.php',
            'Tests\\Feature\\PaymentTest',
            'testReservationPayment',
            10,
            14,
            'Tests\\Feature\\PaymentTest',
        );
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            testImpact: TestImpactResult::fromImpacts([
                new TestImpact(
                    'App\\Services\\ReservationService::updateStatus',
                    $direct,
                    1,
                    TestImpactType::Direct,
                ),
                new TestImpact(
                    'App\\Repositories\\PaymentRepository::update',
                    $indirect,
                    3,
                    TestImpactType::Indirect,
                ),
            ]),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString(
            <<<'TEXT'
Test impact:
  Direct:
    tests/Unit/ReservationServiceTest.php
      Tests\Unit\ReservationServiceTest::testUpdateStatus

  Indirect:
    tests/Feature/PaymentTest.php
      Tests\Feature\PaymentTest::testReservationPayment
        depth: 3
TEXT,
            $text,
        );

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'changed_symbol' => 'App\\Services\\ReservationService::updateStatus',
                    'test_symbol' => 'Tests\\Unit\\ReservationServiceTest::testUpdateStatus',
                    'test_file' => 'tests/Unit/ReservationServiceTest.php',
                    'depth' => 1,
                    'impact' => 'direct',
                ],
                [
                    'changed_symbol' => 'App\\Repositories\\PaymentRepository::update',
                    'test_symbol' => 'Tests\\Feature\\PaymentTest::testReservationPayment',
                    'test_file' => 'tests/Feature/PaymentTest.php',
                    'depth' => 3,
                    'impact' => 'indirect',
                ],
            ],
            $payload['test_impact'],
        );
    }

    public function testTextAndJsonOutputIncludeProjectIndexCounts(): void
    {
        $symbol = new Symbol(
            SymbolType::Class_,
            'Example',
            'Example',
            'src/Example.php',
            1,
            3,
        );
        $dependency = new Dependency(
            'Example::run',
            'PaymentService',
            DependencyType::ParameterType,
            [5],
            1,
        );
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            repositoryIndex: new RepositoryIndex(
                phpFiles: ['src/A.php', 'src/B.php', 'tests/ATest.php'],
                symbols: [$symbol],
                dependencies: [$dependency],
                symbolsByFile: ['src/A.php' => [$symbol]],
                dependenciesByFile: ['src/A.php' => [$dependency]],
                symbolsById: ['Example' => $symbol],
            ),
        );

        $text = (new TextReportFormatter())->format($result);
        $this->assertStringContainsString("Repository index:\n  PHP files: 3\n  Symbols: 1\n  Dependencies: 1", $text);

        $payload = json_decode((new JsonReportFormatter())->format($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                'php_files' => 3,
                'symbols' => 1,
                'dependencies' => 1,
            ],
            $payload['project_index'],
        );
    }

    public function testTextErrorOutputOmitsRawProcessDetails(): void
    {
        $output = (new TextReportFormatter())->format($this->errorResult());

        $this->assertSame(
            "Ripple could not analyze this directory:\nnot a Git repository.",
            $output,
        );
        $this->assertStringNotContainsString('fatal:', $output);
    }

    public function testJsonErrorOutputIncludesTheApplicationMessage(): void
    {
        $payload = json_decode(
            (new JsonReportFormatter())->format($this->errorResult()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('error', $payload['status']);
        $this->assertSame(
            "Ripple could not analyze this directory:\nnot a Git repository.",
            $payload['message'],
        );
        $this->assertArrayNotHasKey('diff', $payload);
        $this->assertArrayNotHasKey('changed_symbols', $payload);
    }

    private function successfulResult(): AnalysisResult
    {
        return new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile(
                    'src/ReservationService.php',
                    ChangeType::Modified,
                    [11, 12],
                    [10],
                ),
                new ChangedFile(
                    'src/NewService.php',
                    ChangeType::Added,
                    range(1, 48),
                    [],
                ),
            ]),
        );
    }

    private function resultWithChangedSymbols(): AnalysisResult
    {
        $file = 'src/Services/ReservationService.php';

        return new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([
                new ChangedFile($file, ChangeType::Modified, [3, 4, 35, 36, 40], []),
            ]),
            changedSymbols: new ChangedSymbolResult(
                [
                    new ChangedSymbol(
                        new Symbol(
                            SymbolType::Method,
                            'updateStatus',
                            'App\\Services\\ReservationService::updateStatus',
                            $file,
                            30,
                            40,
                            'App\\Services\\ReservationService',
                            'public',
                            false,
                        ),
                        [35, 36, 40],
                    ),
                ],
                [
                    new UnmappedFileLines($file, [3, 4]),
                ],
                [],
            ),
        );
    }

    private function errorResult(): AnalysisResult
    {
        return new AnalysisResult(
            status: 'error',
            message: "Ripple could not analyze this directory:\nnot a Git repository.",
        );
    }
}
