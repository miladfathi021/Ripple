<?php

declare(strict_types=1);

namespace Ripple\CLI;

use InvalidArgumentException;
use Ripple\AI\AIConfigurationLoader;
use Ripple\AI\AIProviderException;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIPrExplanationService;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\AI\Explanation\AIRiskExplanationService;
use Ripple\AI\NullAIProvider;
use Ripple\AI\Testing\AITestRecommendation;
use Ripple\AI\Testing\AITestRecommendationService;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\AnalysisRunner;
use Ripple\Reporting\PullRequestCommentFormatter;
use Ripple\Reporting\ReportFormatterFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze the blast radius of code changes',
)]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly AnalysisRunner $analysisRunner,
        private readonly ReportFormatterFactory $reportFormatterFactory,
        private readonly AIPrExplanationService $explanationService = new AIPrExplanationService(new NullAIProvider()),
        private readonly AIRiskExplanationService $riskExplanationService = new AIRiskExplanationService(new NullAIProvider()),
        private readonly AIConfigurationLoader $aiConfigurationLoader = new AIConfigurationLoader(),
        private readonly string $workingDirectory = '.',
        private readonly AITestRecommendationService $testRecommendationService = new AITestRecommendationService(new NullAIProvider()),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format (text, json, or comment)',
            'text',
        );
        $this->addOption(
            'comment-file',
            null,
            InputOption::VALUE_REQUIRED,
            'Write a compact pull-request comment to this path',
        );
        $this->addOption(
            'ai',
            null,
            InputOption::VALUE_NONE,
            'Request optional AI explanations and test recommendations of the deterministic analysis result',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');

        try {
            $formatter = $this->reportFormatterFactory->forFormat($format);
        } catch (InvalidArgumentException $exception) {
            $this->writeError($output, $exception->getMessage());

            return Command::INVALID;
        }

        $result = $this->analysisRunner->run();
        [$explanation, $riskExplanation, $testRecommendation] = $this->explanationsIfRequested($input, $output, $result);
        $output->writeln($formatter->format($result, $explanation, $riskExplanation, $testRecommendation));

        $commentFile = $input->getOption('comment-file');
        if (is_string($commentFile) && $commentFile !== '') {
            $written = @file_put_contents(
                $commentFile,
                (new PullRequestCommentFormatter())->format($result, $explanation, $riskExplanation, $testRecommendation) . "\n",
            );
            if ($written === false) {
                $this->writeError($output, 'Ripple could not write the comment file.');
            }
        }

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{0: ?AIPrExplanation, 1: ?AIRiskExplanation, 2: ?AITestRecommendation}
     */
    private function explanationsIfRequested(
        InputInterface $input,
        OutputInterface $output,
        AnalysisResult $result,
    ): array {
        if ($input->getOption('ai') !== true || !$result->isSuccessful()) {
            return [null, null, null];
        }

        try {
            $configuration = $this->aiConfigurationLoader->load(
                AIConfigurationLoader::pathForWorkingDirectory($this->workingDirectory),
            );
        } catch (AIProviderException $exception) {
            $this->writeError($output, 'AI explanation failed: ' . $exception->getMessage());

            return [null, null, null];
        }

        if (!$configuration->isEnabled()) {
            return [null, null, null];
        }

        $pr = $this->tryExplain(
            $output,
            fn (): AIPrExplanation => $this->explanationService->explain($result),
        );
        $risk = $this->tryExplain(
            $output,
            fn (): AIRiskExplanation => $this->riskExplanationService->explain($result),
        );
        $tests = $this->tryExplain(
            $output,
            fn (): AITestRecommendation => $this->testRecommendationService->recommend($result),
        );

        return [
            $pr instanceof AIPrExplanation && $pr->wasGenerated() ? $pr : null,
            $risk instanceof AIRiskExplanation && $risk->wasGenerated() ? $risk : null,
            $tests instanceof AITestRecommendation && $tests->wasGenerated() ? $tests : null,
        ];
    }

    private function tryExplain(OutputInterface $output, callable $explain): mixed
    {
        try {
            return $explain();
        } catch (AIProviderException $exception) {
            $this->writeError($output, 'AI explanation failed: ' . $exception->getMessage());

            return null;
        }
    }

    private function writeError(OutputInterface $output, string $message): void
    {
        $errorOutput = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : null;
        if ($errorOutput instanceof OutputInterface) {
            $errorOutput->writeln('<error>' . $message . '</error>');
        }
    }
}
