<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIResponse;
use Ripple\AI\Explanation\AIPrExplanationService;
use Ripple\AI\Explanation\AIRiskExplanationService;
use Ripple\AI\Testing\AITestRecommendationService;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Git\GitRepository;
use Ripple\Reporting\ReportFormatterFactory;
use Ripple\Tests\Support\RecordingAIProvider;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeCommandAiTest extends TestCase
{
    public function testAiFlagDoesNotCallTheProviderWhenAIIsDisabled(): void
    {
        $provider = new RecordingAIProvider(AIResponse::generated('should not appear'));
        $repository = $this->repository();
        $tester = $this->tester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertArrayNotHasKey('ai', $payload);
        $this->assertSame([], $provider->requests);
    }

    public function testAiFlagDoesNotFabricateOutputWithNullProviderWhenEnabled(): void
    {
        $repository = $this->repositoryWithAiEnabled();
        $command = new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($repository->path)),
            new ReportFormatterFactory(),
            workingDirectory: $repository->path,
        );
        $tester = new CommandTester($command);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertArrayNotHasKey('ai', $payload);
    }

    public function testAiFlagIncludesGeneratedExplanationWhenEnabled(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('This change may affect the reservation update flow.'),
        );
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->tester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertCount(1, $provider->requests);
        $this->assertSame(
            [
                'generated' => true,
                'text' => 'This change may affect the reservation update flow.',
            ],
            $payload['ai_explanation'],
        );
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('low', $payload['risk_score']['level']);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
    }

    public function testProviderFailureDoesNotCorruptJsonOrInventAnExplanation(): void
    {
        $provider = new RecordingAIProvider(
            exception: new AIProviderException('provider unavailable'),
        );
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->tester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('risk_score', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertCount(1, $provider->requests);
    }

    public function testWithoutAiFlagTheProviderIsNotCalledEvenWhenEnabled(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('This change may affect the reservation update flow.'),
        );
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->tester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json']);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame([], $provider->requests);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
    }

    public function testAiFlagIncludesGeneratedRiskExplanationWhenEnabled(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('Ripple rated this change High Risk with a score of 67.'),
        );
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->riskTester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertCount(1, $provider->requests);
        $this->assertStringContainsString('Risk score:', $provider->requests[0]->input);
        $this->assertSame(
            [
                'generated' => true,
                'text' => 'Ripple rated this change High Risk with a score of 67.',
            ],
            $payload['ai_risk_explanation'],
        );
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('low', $payload['risk_score']['level']);
        $this->assertSame([], $payload['risk_score']['contributions']);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
    }

    public function testRiskProviderFailureDoesNotChangeDeterministicRiskScore(): void
    {
        $provider = new RecordingAIProvider(
            exception: new AIProviderException('provider unavailable'),
        );
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->riskTester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('low', $payload['risk_score']['level']);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertCount(1, $provider->requests);
    }

    public function testAiFlagIncludesGeneratedTestRecommendationsWhenEnabled(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('Consider updating ReservationServiceTest::testUpdateStatus().'),
        );
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->testRecommendationTester($repository->path, $provider);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertCount(1, $provider->requests);
        $this->assertStringContainsString('Test impact', $provider->requests[0]->input);
        $this->assertSame(
            [
                'generated' => true,
                'text' => 'Consider updating ReservationServiceTest::testUpdateStatus().',
            ],
            $payload['ai_test_recommendations'],
        );
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('low', $payload['risk_score']['level']);
        $this->assertArrayHasKey('test_impact', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
    }

    public function testAllGeneratedAiSectionsAppearIndependently(): void
    {
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->isolatedTester(
            $repository->path,
            new RecordingAIProvider(AIResponse::generated('This change may affect the reservation update flow.')),
            new RecordingAIProvider(AIResponse::generated('Ripple rated this change High Risk with a score of 67.')),
            new RecordingAIProvider(AIResponse::generated('Consider updating ReservationServiceTest::testUpdateStatus().')),
        );
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('This change may affect the reservation update flow.', $payload['ai_explanation']['text']);
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $payload['ai_risk_explanation']['text']);
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $payload['ai_test_recommendations']['text'],
        );
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertArrayHasKey('test_impact', $payload);
    }

    public function testRiskFailureKeepsPrExplanationAndTestRecommendations(): void
    {
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->isolatedTester(
            $repository->path,
            new RecordingAIProvider(AIResponse::generated('This change may affect the reservation update flow.')),
            new RecordingAIProvider(exception: new AIProviderException('risk provider unavailable')),
            new RecordingAIProvider(AIResponse::generated('Consider updating ReservationServiceTest::testUpdateStatus().')),
        );
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('This change may affect the reservation update flow.', $payload['ai_explanation']['text']);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $payload['ai_test_recommendations']['text'],
        );
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertArrayHasKey('test_impact', $payload);
    }

    public function testPrFailureKeepsRiskExplanationAndTestRecommendations(): void
    {
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->isolatedTester(
            $repository->path,
            new RecordingAIProvider(exception: new AIProviderException('pr provider unavailable')),
            new RecordingAIProvider(AIResponse::generated('Ripple rated this change High Risk with a score of 67.')),
            new RecordingAIProvider(AIResponse::generated('Consider updating ReservationServiceTest::testUpdateStatus().')),
        );
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $payload['ai_risk_explanation']['text']);
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $payload['ai_test_recommendations']['text'],
        );
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('risk_score', $payload);
    }

    public function testTestRecommendationFailureKeepsPrAndRiskExplanations(): void
    {
        $repository = $this->repositoryWithAiEnabled();
        $tester = $this->isolatedTester(
            $repository->path,
            new RecordingAIProvider(AIResponse::generated('This change may affect the reservation update flow.')),
            new RecordingAIProvider(AIResponse::generated('Ripple rated this change High Risk with a score of 67.')),
            new RecordingAIProvider(exception: new AIProviderException('test provider unavailable')),
        );
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('This change may affect the reservation update flow.', $payload['ai_explanation']['text']);
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $payload['ai_risk_explanation']['text']);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertArrayHasKey('test_impact', $payload);
    }

    private function tester(string $workingDirectory, RecordingAIProvider $provider): CommandTester
    {
        return new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
            new AIPrExplanationService($provider),
            workingDirectory: $workingDirectory,
        ));
    }

    private function riskTester(string $workingDirectory, RecordingAIProvider $provider): CommandTester
    {
        return new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
            riskExplanationService: new AIRiskExplanationService($provider),
            workingDirectory: $workingDirectory,
        ));
    }

    private function testRecommendationTester(string $workingDirectory, RecordingAIProvider $provider): CommandTester
    {
        return new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
            testRecommendationService: new AITestRecommendationService($provider),
            workingDirectory: $workingDirectory,
        ));
    }

    private function isolatedTester(
        string $workingDirectory,
        RecordingAIProvider $prProvider,
        RecordingAIProvider $riskProvider,
        RecordingAIProvider $testProvider,
    ): CommandTester {
        return new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
            new AIPrExplanationService($prProvider),
            new AIRiskExplanationService($riskProvider),
            workingDirectory: $workingDirectory,
            testRecommendationService: new AITestRecommendationService($testProvider),
        ));
    }

    private function repositoryWithAiEnabled(): TemporaryGitRepository
    {
        $repository = $this->repository();
        $repository->write('ripple.json', <<<'JSON'
{
  "ai": {
    "enabled": true,
    "provider": "openai"
  }
}
JSON);

        return $repository;
    }

    private function repository(): TemporaryGitRepository
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', "<?php\nclass Example {}\n", 'initial');

        return $repository;
    }
}
