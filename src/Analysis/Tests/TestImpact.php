<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

final readonly class TestImpact
{
    public function __construct(
        public string $changedSymbolId,
        public TestSymbol $test,
        public int $depth,
        public TestImpactType $impact,
    ) {
    }
}
