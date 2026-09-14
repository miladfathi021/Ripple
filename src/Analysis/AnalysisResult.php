<?php

declare(strict_types=1);

namespace Ripple\Analysis;

final readonly class AnalysisResult
{
    public function __construct(
        public string $status,
        public string $message,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'ready';
    }
}
