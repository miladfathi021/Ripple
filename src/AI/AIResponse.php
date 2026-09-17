<?php

declare(strict_types=1);

namespace Ripple\AI;

final readonly class AIResponse
{
    public function __construct(
        public string $text,
        public bool $generated = true,
    ) {
    }

    public static function none(): self
    {
        return new self('', false);
    }

    public static function generated(string $text): self
    {
        return new self($text, true);
    }

    public function wasGenerated(): bool
    {
        return $this->generated;
    }
}
