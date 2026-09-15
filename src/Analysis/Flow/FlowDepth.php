<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

final readonly class FlowDepth
{
    public const DEFAULT = 3;

    public function __construct(
        public int $value,
    ) {
        if ($value < 0) {
            throw new InvalidFlowDepthException(
                "Flow depth must be 0 or greater, got {$value}.",
            );
        }
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }
}
