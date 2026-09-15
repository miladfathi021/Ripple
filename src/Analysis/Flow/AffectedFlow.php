<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

use Ripple\Analysis\Graph\GraphEdge;

final readonly class AffectedFlow
{
    /**
     * @param list<FlowNode> $nodes Downstream nodes only; the changed symbol is the path origin.
     * @param list<GraphEdge> $edges Original graph edges in path order.
     */
    public function __construct(
        public string $changedSymbolId,
        public FlowType $type,
        public array $nodes,
        public array $edges,
        public int $depth,
    ) {
    }
}
