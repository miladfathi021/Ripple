<?php

declare(strict_types=1);

namespace Ripple\AI\Explanation;

final readonly class AIRiskExplanation
{
    public function __construct(
        public string $text,
        public bool $generated,
    ) {
    }

    public static function none(): self
    {
        return new self('', false);
    }

    public function wasGenerated(): bool
    {
        return $this->generated && trim($this->text) !== '';
    }
}
