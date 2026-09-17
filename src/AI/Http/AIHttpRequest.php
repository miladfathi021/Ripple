<?php

declare(strict_types=1);

namespace Ripple\AI\Http;

final readonly class AIHttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public string $body,
        public int $timeoutSeconds,
    ) {
    }
}
