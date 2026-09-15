<?php

declare(strict_types=1);

namespace Ripple\Analysis\Dependencies;

use PhpParser\NodeTraverser;
use Ripple\Analysis\AST\ParsedPhpFile;

final class DependencyExtractor
{
    public function extract(ParsedPhpFile $file): DependencyResult
    {
        $visitor = new DependencyCollectingVisitor($file->result->symbols);
        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($file->statements);

        return new DependencyResult(DependencyResult::sort($visitor->dependencies()));
    }
}
