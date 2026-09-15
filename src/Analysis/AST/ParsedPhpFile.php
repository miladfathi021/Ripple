<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

use PhpParser\Node\Stmt;

final readonly class ParsedPhpFile
{
    /**
     * @param list<Stmt> $statements
     */
    public function __construct(
        public string $file,
        public array $statements,
        public AstResult $result,
    ) {
    }
}
