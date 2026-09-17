<?php

declare(strict_types=1);

namespace Ripple\AI;

final readonly class AIRequest
{
    public function __construct(
        public string $input,
        public string $instructions = '',
    ) {
        if (trim($this->input) === '') {
            throw new AIProviderException('AI request input must be a non-empty string.');
        }
    }
}
