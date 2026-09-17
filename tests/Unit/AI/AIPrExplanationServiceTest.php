<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIResponse;
use Ripple\AI\Explanation\AIPrExplanationService;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\Tests\Support\RecordingAIProvider;

final class AIPrExplanationServiceTest extends TestCase
{
    public function testGeneratedResponseBecomesAnExplanation(): void
    {
        $provider = new RecordingAIProvider(
            AIResponse::generated('This change may affect the reservation update flow.'),
        );
        $service = new AIPrExplanationService($provider);
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));

        $explanation = $service->explain($result);

        $this->assertCount(1, $provider->requests);
        $this->assertNotSame('', $provider->requests[0]->input);
        $this->assertStringContainsString('Risk:', $provider->requests[0]->input);
        $this->assertTrue($explanation->wasGenerated());
        $this->assertSame('This change may affect the reservation update flow.', $explanation->text);
    }

    public function testNonGeneratedResponseProducesNoExplanationText(): void
    {
        $provider = new RecordingAIProvider(AIResponse::none());
        $explanation = (new AIPrExplanationService($provider))->explain(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertCount(1, $provider->requests);
        $this->assertFalse($explanation->wasGenerated());
        $this->assertSame('', $explanation->text);
    }

    public function testEmptyGeneratedTextIsNotTreatedAsAnExplanation(): void
    {
        $provider = new RecordingAIProvider(AIResponse::generated('   '));
        $explanation = (new AIPrExplanationService($provider))->explain(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertFalse($explanation->wasGenerated());
        $this->assertSame('', $explanation->text);
    }

    public function testFailedAnalysisDoesNotCallTheProvider(): void
    {
        $provider = new RecordingAIProvider(AIResponse::generated('should not appear'));
        $explanation = (new AIPrExplanationService($provider))->explain(
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
        $service = new AIPrExplanationService($provider);

        try {
            $service->explain(new AnalysisResult(status: 'ok', diff: new DiffResult([])));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $this->assertCount(1, $provider->requests);
    }

    public function testServiceDoesNotImportDeterministicCalculators(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AI/Explanation/AIPrExplanationService.php',
        );

        $this->assertStringNotContainsString('RiskScoreCalculator', $source);
        $this->assertStringNotContainsString('TransitiveImpactAnalyzer', $source);
        $this->assertStringNotContainsString('DirectImpactAnalyzer', $source);
        $this->assertStringNotContainsString('RiskFactorAnalyzer', $source);
    }
}
