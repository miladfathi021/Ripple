<?php

declare(strict_types=1);

namespace Ripple\AI\Testing;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\AIProvider;
use Ripple\AI\AIResponse;

final class AITestRecommendationService
{
    public function __construct(
        private readonly AIProvider $provider,
        private readonly AITestRecommendationRequestBuilder $requestBuilder = new AITestRecommendationRequestBuilder(),
    ) {
    }

    public function recommend(AnalysisResult $result): AITestRecommendation
    {
        if (!$result->isSuccessful()) {
            return AITestRecommendation::none();
        }

        $response = $this->provider->generate($this->requestBuilder->build($result));

        return $this->fromResponse($response);
    }

    private function fromResponse(AIResponse $response): AITestRecommendation
    {
        if (!$response->wasGenerated() || trim($response->text) === '') {
            return AITestRecommendation::none();
        }

        return AITestRecommendation::generated($response->text);
    }
}
