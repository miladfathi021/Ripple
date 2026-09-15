<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

final readonly class AstResult
{
    /**
     * @param list<string> $uses
     * @param list<Symbol> $symbols
     */
    public function __construct(
        public string $file,
        public ?string $namespace,
        public array $uses,
        public array $symbols,
    ) {
    }
}
