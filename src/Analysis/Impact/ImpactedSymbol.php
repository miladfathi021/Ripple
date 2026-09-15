<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

use Ripple\Analysis\Graph\GraphEdge;

final readonly class ImpactedSymbol
{
    /**
     * @param list<GraphEdge> $edges Original forward edges: impacted symbol → changed symbol.
     * @param list<string> $causedBy Changed symbol IDs that produced this impact.
     */
    public function __construct(
        public string $id,
        public array $edges,
        public array $causedBy,
    ) {
    }
}
