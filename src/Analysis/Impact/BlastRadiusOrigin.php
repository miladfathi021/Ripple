<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

use Ripple\Analysis\Graph\GraphEdge;

final readonly class BlastRadiusOrigin
{
    public function __construct(
        public string $changedSymbolId,
        public int $depth,
        public GraphEdge $edge,
    ) {
    }
}
