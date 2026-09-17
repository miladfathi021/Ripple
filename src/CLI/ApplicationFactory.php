<?php

declare(strict_types=1);

namespace Ripple\CLI;

use Ripple\AI\AIConfigurationLoader;
use Ripple\AI\Explanation\AIPrExplanationService;
use Ripple\AI\Explanation\AIRiskExplanationService;
use Ripple\AI\NullAIProvider;
use Ripple\AI\Testing\AITestRecommendationService;
use Ripple\Analysis\AnalysisRunner;
use Ripple\Analysis\Index\RepositoryIndexHolder;
use Ripple\Analysis\Semantics\SemanticImpactAnalyzer;
use Ripple\Git\GitRepository;
use Ripple\Reporting\ReportFormatterFactory;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    private readonly AnalysisRunner $analysisRunner;
    private readonly AIPrExplanationService $explanationService;
    private readonly AIRiskExplanationService $riskExplanationService;
    private readonly AITestRecommendationService $testRecommendationService;

    public function __construct(
        ?AnalysisRunner $analysisRunner = null,
        private readonly ReportFormatterFactory $reportFormatterFactory = new ReportFormatterFactory(),
        ?AIPrExplanationService $explanationService = null,
        private readonly AIConfigurationLoader $aiConfigurationLoader = new AIConfigurationLoader(),
        private readonly string $workingDirectory = '.',
        ?AIRiskExplanationService $riskExplanationService = null,
        ?AITestRecommendationService $testRecommendationService = null,
    ) {
        $this->analysisRunner = $analysisRunner ?? self::runnerForWorkingDirectory($workingDirectory);
        $provider = new NullAIProvider();
        $this->explanationService = $explanationService ?? new AIPrExplanationService($provider);
        $this->riskExplanationService = $riskExplanationService ?? new AIRiskExplanationService($provider);
        $this->testRecommendationService = $testRecommendationService ?? new AITestRecommendationService($provider);
    }

    public static function forWorkingDirectory(string $workingDirectory): self
    {
        return new self(
            self::runnerForWorkingDirectory($workingDirectory),
            workingDirectory: $workingDirectory,
        );
    }

    public static function runnerForWorkingDirectory(string $workingDirectory): AnalysisRunner
    {
        $indexHolder = new RepositoryIndexHolder();

        return new AnalysisRunner(
            new GitRepository($workingDirectory),
            semanticImpactAnalyzer: new SemanticImpactAnalyzer(
                new ConfiguredSemanticAnnotationProvider($workingDirectory, $indexHolder),
            ),
            indexHolder: $indexHolder,
        );
    }

    public function create(): Application
    {
        $application = new Application('Ripple', '0.1.0');
        $application->add(new AnalyzeCommand(
            $this->analysisRunner,
            $this->reportFormatterFactory,
            $this->explanationService,
            $this->riskExplanationService,
            $this->aiConfigurationLoader,
            $this->workingDirectory,
            $this->testRecommendationService,
        ));
        $application->setDefaultCommand('analyze');

        return $application;
    }
}
