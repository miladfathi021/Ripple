<?php

declare(strict_types=1);

namespace Ripple\AI\Http;

final readonly class AIHttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {
    }
}
