<?php

declare(strict_types=1);

namespace Ripple\Tests\Support;

use Ripple\AI\AIProvider;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIRequest;
use Ripple\AI\AIResponse;

final class RecordingAIProvider implements AIProvider
{
    /** @var list<AIRequest> */
    public array $requests = [];

    public function __construct(
        private readonly AIResponse $response = new AIResponse('', false),
        private readonly ?AIProviderException $exception = null,
    ) {
    }

    public function generate(AIRequest $request): AIResponse
    {
        $this->requests[] = $request;

        if ($this->exception instanceof AIProviderException) {
            throw $this->exception;
        }

        return $this->response;
    }
}
