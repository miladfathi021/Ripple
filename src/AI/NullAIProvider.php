<?php

declare(strict_types=1);

namespace Ripple\AI;

final class NullAIProvider implements AIProvider
{
    public function generate(AIRequest $request): AIResponse
    {
        return AIResponse::none();
    }
}
