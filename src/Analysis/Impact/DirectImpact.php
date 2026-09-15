<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

use Ripple\Analysis\Graph\GraphEdge;

final readonly class DirectImpact
{
    public function __construct(
        public string $changedSymbolId,
        public string $impactedSymbolId,
        public GraphEdge $edge,
    ) {
    }
}
