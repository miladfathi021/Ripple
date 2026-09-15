<?php

declare(strict_types=1);

namespace Ripple\Analysis\Dependencies;

final readonly class Dependency
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

    public function line(): int
    {
        return $this->lines[0] ?? 0;
    }
}
