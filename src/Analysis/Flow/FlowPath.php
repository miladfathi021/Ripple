<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

use Ripple\Analysis\Graph\GraphEdge;

final readonly class FlowPath
{
    /**
     * @param list<GraphEdge> $edges
     */
    public function __construct(
        public string $originId,
        public array $edges,
    ) {
    }

    public function depth(): int
    {
        return count($this->edges);
    }

    public function terminalId(): string
    {
        return $this->edges[array_key_last($this->edges)]->target;
    }
}
