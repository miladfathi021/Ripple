<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

final readonly class FlowNode
{
    public function __construct(
        public string $id,
        public int $depth,
        public bool $known,
    ) {
    }
}
