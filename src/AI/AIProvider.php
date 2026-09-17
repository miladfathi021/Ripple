<?php

declare(strict_types=1);

namespace Ripple\AI;

interface AIProvider
{
    public function generate(AIRequest $request): AIResponse;
}
