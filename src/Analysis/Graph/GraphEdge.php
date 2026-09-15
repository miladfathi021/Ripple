<?php

declare(strict_types=1);

namespace Ripple\Analysis\Graph;

use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyType;

final readonly class GraphEdge
{
    /**
     * @param list<int> $lines
     */
    public function __construct(
        public string $source,
        public string $target,
        public DependencyType $type,
        public array $lines,
        public int $occurrences,
    ) {
    }

    public static function fromDependency(Dependency $dependency): self
    {
        return new self(
            source: $dependency->source,
            target: $dependency->target,
            type: $dependency->type,
            lines: $dependency->lines,
            occurrences: $dependency->occurrences,
        );
    }
}
