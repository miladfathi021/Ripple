<?php

declare(strict_types=1);

namespace Ripple\AI;

final readonly class AIConfiguration
{
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        public bool $enabled = false,
        public ?string $provider = null,
        public ?string $model = null,
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public ?string $baseUrl = null,
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
