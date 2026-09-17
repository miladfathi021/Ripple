<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIResponse;
use Ripple\AI\Testing\AITestRecommendationService;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\Tests\Support\RecordingAIProvider;

final class AITestRecommendationServiceTest extends TestCase
{
    public function testProviderIsCalledOnceAndGeneratedResponseBecomesARecommendation(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('Consider updating ReservationServiceTest::testUpdateStatus().'),
        );
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));
        $recommendation = (new AITestRecommendationService($provider))->recommend($result);

        $this->assertCount(1, $provider->requests);
        $this->assertStringContainsString('Test impact', $provider->requests[0]->input);
        $this->assertStringContainsString('Never invent test names', $provider->requests[0]->instructions);
        $this->assertTrue($recommendation->wasGenerated());
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $recommendation->text(),
        );
        $this->assertSame('ok', $result->status);
    }

    public function testNonGeneratedResponseProducesNoRecommendation(): void
    {
        $provider = new RecordingAIProvider(AIResponse::none());
        $recommendation = (new AITestRecommendationService($provider))->recommend(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertCount(1, $provider->requests);
        $this->assertFalse($recommendation->wasGenerated());
        $this->assertSame('', $recommendation->text());
    }

    public function testEmptyGeneratedTextIsNotTreatedAsARecommendation(): void
    {
        $provider = new RecordingAIProvider(AIResponse::generated('   '));
        $recommendation = (new AITestRecommendationService($provider))->recommend(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertFalse($recommendation->wasGenerated());
        $this->assertSame('', $recommendation->text());
    }

    public function testFailedAnalysisDoesNotCallTheProvider(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('Consider updating ReservationServiceTest::testUpdateStatus().'),
        );
        $result = new AnalysisResult(status: 'error', message: 'not a Git repository.');
        $recommendation = (new AITestRecommendationService($provider))->recommend($result);

        $this->assertSame([], $provider->requests);
        $this->assertFalse($recommendation->wasGenerated());
        $this->assertSame('error', $result->status);
        $this->assertSame('not a Git repository.', $result->message);
    }

    public function testProviderExceptionPropagatesWithoutFabricatingARecommendation(): void
    {
        $provider = new RecordingAIProvider(
            exception: new AIProviderException('provider unavailable'),
        );
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));

        try {
            (new AITestRecommendationService($provider))->recommend($result);
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $this->assertCount(1, $provider->requests);
        $this->assertSame('ok', $result->status);
    }

    public function testServiceDoesNotCalculateTestImpactOrCoverage(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AI/Testing/AITestRecommendationService.php',
        );

        $this->assertStringNotContainsString('TestImpactAnalyzer', $source);
        $this->assertStringNotContainsString('RiskScoreCalculator', $source);
        $this->assertStringNotContainsString('TransitiveImpactAnalyzer', $source);
        $this->assertStringNotContainsString('phpunit', $source);
        $this->assertStringNotContainsString('coverage', $source);
    }
}
