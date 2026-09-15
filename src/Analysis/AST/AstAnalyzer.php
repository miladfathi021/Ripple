<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

final class AstAnalyzer
{
    public function __construct(
        private readonly AstParser $parser = new AstParser(),
        private readonly SymbolExtractor $extractor = new SymbolExtractor(),
    ) {
    }

    public function analyze(string $source, string $file = ''): AstResult
    {
        return $this->parse($source, $file)->result;
    }

    public function parse(string $source, string $file = ''): ParsedPhpFile
    {
        $statements = $this->parser->parse($source, $file);

        return new ParsedPhpFile(
            file: $file,
            statements: $statements,
            result: $this->extractor->extract($statements, $file),
        );
    }
}
