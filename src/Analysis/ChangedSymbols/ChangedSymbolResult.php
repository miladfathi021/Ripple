<?php

declare(strict_types=1);

namespace Ripple\Analysis\ChangedSymbols;

final readonly class ChangedSymbolResult
{
    /**
     * @param list<ChangedSymbol> $changedSymbols
     * @param list<UnmappedFileLines> $unmappedLines
     * @param list<UnmappedFileLines> $unmappedDeletedLines
     */
    public function __construct(
        public array $changedSymbols,
        public array $unmappedLines,
        public array $unmappedDeletedLines,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }
}
