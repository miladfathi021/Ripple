<?php

declare(strict_types=1);

namespace Ripple\Analysis\ChangedSymbols;

final readonly class UnmappedFileLines
{
    /**
     * @param list<int> $lines
     */
    public function __construct(
        public string $file,
        public array $lines,
    ) {
    }
}
