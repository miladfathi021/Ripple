<?php

declare(strict_types=1);

namespace Ripple\AI\Explanation;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\AIProvider;
use Ripple\AI\AIResponse;

final class AIRiskExplanationService
{
    public function __construct(
        private readonly AIProvider $provider,
        private readonly AIRiskExplanationRequestBuilder $requestBuilder = new AIRiskExplanationRequestBuilder(),
    ) {
    }

    public function explain(AnalysisResult $result): AIRiskExplanation
    {
        if (!$result->isSuccessful()) {
            return AIRiskExplanation::none();
        }

        $response = $this->provider->generate($this->requestBuilder->build($result));

        return $this->fromResponse($response);
    }

    private function fromResponse(AIResponse $response): AIRiskExplanation
    {
        if (!$response->wasGenerated() || trim($response->text) === '') {
            return AIRiskExplanation::none();
        }

        return new AIRiskExplanation($response->text, true);
    }
}
