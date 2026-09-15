<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

final class SymbolExtractor
{
    /**
     * @param list<Stmt> $statements
     */
    public function extract(array $statements, string $file = ''): AstResult
    {
        $collector = new SymbolCollectingVisitor($file);
        $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true]), $collector);
        $traverser->traverse($statements);

        return $collector->result();
    }
}
