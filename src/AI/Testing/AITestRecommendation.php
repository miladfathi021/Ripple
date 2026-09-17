<?php

declare(strict_types=1);

namespace Ripple\AI\Testing;

final readonly class AITestRecommendation
{
    public function __construct(
        public string $text,
        public bool $generated,
    ) {
    }

    public static function generated(string $text): self
    {
        return new self($text, true);
    }

    public static function none(): self
    {
        return new self('', false);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function wasGenerated(): bool
    {
        return $this->generated && trim($this->text) !== '';
    }
}
