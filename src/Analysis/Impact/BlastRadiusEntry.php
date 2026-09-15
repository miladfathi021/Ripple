<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

final readonly class BlastRadiusEntry
{
    /**
     * @param list<BlastRadiusOrigin> $origins
     */
    public function __construct(
        public string $impactedSymbolId,
        public int $depth,
        public array $origins,
    ) {
    }
}
