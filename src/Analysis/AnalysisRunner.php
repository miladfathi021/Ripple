<?php

declare(strict_types=1);

namespace Ripple\Analysis;

use Ripple\Analysis\AST\AstAnalyzer;
use Ripple\Analysis\AST\PhpParseException;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolDetector;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyExtractor;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Flow\AffectedFlowAnalyzer;
use Ripple\Analysis\Graph\DependencyGraphBuilder;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;
use Ripple\Analysis\Impact\DirectImpactAnalyzer;
use Ripple\Analysis\Impact\TransitiveImpactAnalyzer;
use Ripple\Analysis\Index\DuplicateSymbolException;
use Ripple\Analysis\Index\RepositoryIndex;
use Ripple\Analysis\Index\RepositoryIndexBuilder;
use Ripple\Analysis\Index\RepositoryIndexException;
use Ripple\Analysis\Index\RepositoryIndexHolder;
use Ripple\Analysis\Risk\RiskFactorAnalyzer;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskScoreCalculator;
use Ripple\Analysis\Semantics\InvalidSemanticConfigurationException;
use Ripple\Analysis\Semantics\SemanticImpactAnalyzer;
use Ripple\Analysis\Tests\TestImpactAnalyzer;
use Ripple\Analysis\Tests\TestSymbolDetector;
use Ripple\Git\DiffResult;
use Ripple\Git\GitDiffParser;
use Ripple\Git\GitOperationFailed;
use Ripple\Git\GitRepository;
use Ripple\Git\History\GitHistoryAnalyzer;
use Ripple\Git\History\GitHistoryAnalysisFailed;
use Ripple\Git\NotAGitRepository;

final class AnalysisRunner
{
    private readonly RepositoryIndexBuilder $indexBuilder;
    private readonly GitHistoryAnalyzer $historyAnalyzer;

    public function __construct(
        private readonly GitRepository $gitRepository = new GitRepository(),
        private readonly GitDiffParser $diffParser = new GitDiffParser(),
        private readonly AstAnalyzer $astAnalyzer = new AstAnalyzer(),
        private readonly ChangedSymbolDetector $changedSymbolDetector = new ChangedSymbolDetector(),
        private readonly DependencyExtractor $dependencyExtractor = new DependencyExtractor(),
        private readonly DependencyGraphBuilder $graphBuilder = new DependencyGraphBuilder(),
        private readonly ReverseDependencyGraphBuilder $reverseGraphBuilder = new ReverseDependencyGraphBuilder(),
        private readonly DirectImpactAnalyzer $directImpactAnalyzer = new DirectImpactAnalyzer(),
        private readonly TransitiveImpactAnalyzer $transitiveImpactAnalyzer = new TransitiveImpactAnalyzer(),
        private readonly RiskFactorAnalyzer $riskFactorAnalyzer = new RiskFactorAnalyzer(),
        private readonly RiskScoreCalculator $riskScoreCalculator = new RiskScoreCalculator(),
        private readonly AffectedFlowAnalyzer $affectedFlowAnalyzer = new AffectedFlowAnalyzer(),
        private readonly SemanticImpactAnalyzer $semanticImpactAnalyzer = new SemanticImpactAnalyzer(),
        private readonly TestSymbolDetector $testSymbolDetector = new TestSymbolDetector(),
        private readonly TestImpactAnalyzer $testImpactAnalyzer = new TestImpactAnalyzer(),
        private readonly ?RepositoryIndexHolder $indexHolder = null,
        ?GitHistoryAnalyzer $historyAnalyzer = null,
        ?RepositoryIndexBuilder $indexBuilder = null,
    ) {
        $this->indexBuilder = $indexBuilder ?? new RepositoryIndexBuilder(
            astAnalyzer: $this->astAnalyzer,
            dependencyExtractor: $this->dependencyExtractor,
        );
        $this->historyAnalyzer = $historyAnalyzer ?? new GitHistoryAnalyzer($this->gitRepository);
    }

    public function run(): AnalysisResult
    {
        try {
            $rawDiff = $this->gitRepository->workingTreeDiff();
        } catch (NotAGitRepository) {
            return new AnalysisResult(
                status: 'error',
                message: "Ripple could not analyze this directory:\nnot a Git repository.",
            );
        } catch (GitOperationFailed $exception) {
            return new AnalysisResult(
                status: 'error',
                message: "Ripple could not analyze this directory:\n" . $exception->getMessage(),
            );
        }

        $diff = $this->diffParser->parse($rawDiff);

        try {
            $index = $this->indexBuilder->build($this->gitRepository->workingDirectory());
        } catch (PhpParseException $exception) {
            return new AnalysisResult(
                status: 'error',
                message: $this->formatParseError($exception),
            );
        } catch (DuplicateSymbolException|RepositoryIndexException $exception) {
            return new AnalysisResult(
                status: 'error',
                message: $exception->getMessage(),
            );
        }

        $graph = $this->graphBuilder->build(
            new DependencyResult($index->getDependencies()),
            $index->getSymbols(),
        );
        $this->indexHolder?->set($index);
        $reverseGraph = $this->reverseGraphBuilder->build($graph);
        $changedSymbols = $this->changedSymbolsFromDiff($diff, $index);
        $directImpact = $this->directImpactAnalyzer->analyze($changedSymbols, $reverseGraph);
        $blastRadius = $this->transitiveImpactAnalyzer->analyze($changedSymbols, $reverseGraph);
        $testImpact = $this->testImpactAnalyzer->analyze(
            $blastRadius,
            $this->testSymbolDetector->detect($index),
        );

        try {
            $churn = $this->historyAnalyzer->analyze($diff->files);
        } catch (GitHistoryAnalysisFailed $exception) {
            return new AnalysisResult(
                status: 'error',
                message: $exception->getMessage(),
            );
        }

        $riskFactors = $this->riskFactorAnalyzer->analyze(new RiskFactorContext(
            $changedSymbols,
            $reverseGraph,
            $blastRadius,
            $churn,
        ));
        $riskScore = $this->riskScoreCalculator->calculate($riskFactors);
        $affectedFlows = $this->affectedFlowAnalyzer->analyze($changedSymbols, $graph);

        try {
            $semanticImpact = $this->semanticImpactAnalyzer->analyze($blastRadius, $affectedFlows);
        } catch (InvalidSemanticConfigurationException $exception) {
            return new AnalysisResult(
                status: 'error',
                message: $exception->getMessage(),
            );
        }

        return new AnalysisResult(
            status: 'ok',
            diff: $diff,
            changedSymbols: $changedSymbols,
            dependencies: $this->dependenciesFromDiff($diff, $index),
            graph: $graph,
            reverseGraph: $reverseGraph,
            repositoryIndex: $index,
            directImpact: $directImpact,
            blastRadius: $blastRadius,
            testImpact: $testImpact,
            riskFactors: $riskFactors,
            riskScore: $riskScore,
            affectedFlows: $affectedFlows,
            semanticImpact: $semanticImpact,
            churn: $churn,
        );
    }

    private function changedSymbolsFromDiff(DiffResult $diff, RepositoryIndex $index): ChangedSymbolResult
    {
        $symbolsByFile = [];
        foreach ($diff->files as $file) {
            if (!str_ends_with(strtolower($file->path), '.php')) {
                continue;
            }

            $symbolsByFile[$file->path] = $index->getSymbolsForFile($file->path);
        }

        return $this->changedSymbolDetector->detect($diff, $symbolsByFile);
    }

    private function dependenciesFromDiff(DiffResult $diff, RepositoryIndex $index): DependencyResult
    {
        $results = [];
        foreach ($diff->files as $file) {
            $dependencies = $index->getDependenciesForFile($file->path);
            if ($dependencies === []) {
                continue;
            }

            $results[] = new DependencyResult($dependencies);
        }

        return DependencyResult::merge($results);
    }

    private function formatParseError(PhpParseException $exception): string
    {
        $file = $exception->sourceFile !== '' ? $exception->sourceFile : 'unknown file';

        return "Ripple could not analyze PHP file:\n{$file}\n\nReason:\n{$exception->getMessage()}";
    }
}
