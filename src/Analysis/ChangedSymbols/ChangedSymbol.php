<?php

declare(strict_types=1);

namespace Ripple\Analysis\ChangedSymbols;

use Ripple\Analysis\AST\Symbol;

final readonly class ChangedSymbol
{
    /**
     * @param list<int> $changedLines
     */
    public function __construct(
        public Symbol $symbol,
        public array $changedLines,
    ) {
    }
}
