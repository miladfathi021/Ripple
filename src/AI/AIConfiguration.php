<?php

declare(strict_types=1);

namespace Ripple\AI;

final readonly class AIConfiguration
{
    public function __construct(
        public bool $enabled = false,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
