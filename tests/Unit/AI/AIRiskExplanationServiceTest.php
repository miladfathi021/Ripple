<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIResponse;
use Ripple\AI\Explanation\AIRiskExplanationService;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\Tests\Support\RecordingAIProvider;

final class AIRiskExplanationServiceTest extends TestCase
{
    public function testGeneratedResponseBecomesARiskExplanation(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('Ripple rated this change High Risk with a score of 67.'),
        );
        $explanation = (new AIRiskExplanationService($provider))->explain(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertCount(1, $provider->requests);
        $this->assertStringContainsString('Risk score:', $provider->requests[0]->input);
        $this->assertStringContainsString('authoritative', $provider->requests[0]->instructions);
        $this->assertTrue($explanation->wasGenerated());
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $explanation->text);
    }

    public function testNonGeneratedResponseProducesNoExplanation(): void
    {
        $provider = new RecordingAIProvider(AIResponse::none());
        $explanation = (new AIRiskExplanationService($provider))->explain(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertCount(1, $provider->requests);
        $this->assertFalse($explanation->wasGenerated());
        $this->assertSame('', $explanation->text);
    }

    public function testFailedAnalysisDoesNotCallTheProvider(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('Ripple rated this change High Risk with a score of 67.'),
        );
        $explanation = (new AIRiskExplanationService($provider))->explain(
            new AnalysisResult(status: 'error', message: 'not a Git repository.'),
        );

        $this->assertSame([], $provider->requests);
        $this->assertFalse($explanation->wasGenerated());
    }

    public function testProviderExceptionPropagatesWithoutFabricatingOutput(): void
    {
        $provider = new RecordingAIProvider(
            exception: new AIProviderException('provider unavailable'),
        );

        try {
            (new AIRiskExplanationService($provider))->explain(
                new AnalysisResult(status: 'ok', diff: new DiffResult([])),
            );
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $this->assertCount(1, $provider->requests);
    }

    public function testServiceDoesNotCalculateRisk(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AI/Explanation/AIRiskExplanationService.php',
        );

        $this->assertStringNotContainsString('RiskScoreCalculator', $source);
        $this->assertStringNotContainsString('RiskFactorAnalyzer', $source);
        $this->assertStringNotContainsString('TransitiveImpactAnalyzer', $source);
        $this->assertStringNotContainsString('GitHistoryAnalyzer', $source);
    }
}
