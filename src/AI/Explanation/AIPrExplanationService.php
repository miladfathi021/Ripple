<?php

declare(strict_types=1);

namespace Ripple\AI\Explanation;

use Ripple\Analysis\AnalysisResult;
use Ripple\AI\AIProvider;
use Ripple\AI\AIResponse;

final class AIPrExplanationService
{
    public function __construct(
        private readonly AIProvider $provider,
        private readonly AIPrExplanationRequestBuilder $requestBuilder = new AIPrExplanationRequestBuilder(),
    ) {
    }

    public function explain(AnalysisResult $result): AIPrExplanation
    {
        if (!$result->isSuccessful()) {
            return AIPrExplanation::none();
        }

        $response = $this->provider->generate($this->requestBuilder->build($result));

        return $this->fromResponse($response);
    }

    private function fromResponse(AIResponse $response): AIPrExplanation
    {
        if (!$response->wasGenerated() || trim($response->text) === '') {
            return AIPrExplanation::none();
        }

        return new AIPrExplanation($response->text, true);
    }
}
