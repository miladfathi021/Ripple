<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI\OpenAI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Explanation\AIPrExplanationService;
use Ripple\AI\Explanation\AIRiskExplanationService;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\AI\OpenAI\OpenAIProvider;
use Ripple\AI\Testing\AITestRecommendationService;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\Tests\Support\FakeAIHttpClient;

final class OpenAIProviderServicesTest extends TestCase
{
    public function testPrExplanationWorksThroughTheOpenAiProvider(): void
    {
        $http = $this->http('This change may affect the reservation update flow.');
        $explanation = (new AIPrExplanationService($this->provider($http)))->explain(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertTrue($explanation->wasGenerated());
        $this->assertSame('This change may affect the reservation update flow.', $explanation->text);
        $this->assertCount(1, $http->requests);
        $payload = json_decode($http->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Risk:', $payload['input']);
        $this->assertArrayNotHasKey('source', $payload);
        $this->assertArrayNotHasKey('files', $payload);
    }

    public function testRiskExplanationWorksThroughTheOpenAiProvider(): void
    {
        $http = $this->http('Ripple rated this change High Risk with a score of 67.');
        $explanation = (new AIRiskExplanationService($this->provider($http)))->explain(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertTrue($explanation->wasGenerated());
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $explanation->text);
        $payload = json_decode($http->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Risk score:', $payload['input']);
    }

    public function testTestRecommendationsWorkThroughTheOpenAiProvider(): void
    {
        $http = $this->http('Consider updating ReservationServiceTest::testUpdateStatus().');
        $recommendation = (new AITestRecommendationService($this->provider($http)))->recommend(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
        );

        $this->assertTrue($recommendation->wasGenerated());
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $recommendation->text(),
        );
        $payload = json_decode($http->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Test impact', $payload['input']);
    }

    private function provider(FakeAIHttpClient $http): OpenAIProvider
    {
        return new OpenAIProvider('sk-test-secret-key-do-not-leak', 'test-model', $http, 30);
    }

    private function http(string $text): FakeAIHttpClient
    {
        return new FakeAIHttpClient(new AIHttpResponse(200, json_encode([
            'output_text' => $text,
        ], JSON_THROW_ON_ERROR)));
    }
}
