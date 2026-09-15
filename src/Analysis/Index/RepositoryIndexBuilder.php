<?php

declare(strict_types=1);

namespace Ripple\Analysis\Index;

use Ripple\Analysis\AST\AstAnalyzer;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\ChangedSymbols\PhpSourceReader;
use Ripple\Analysis\Dependencies\DependencyExtractor;
use Ripple\Analysis\Dependencies\DependencyResult;

final class RepositoryIndexBuilder
{
    public function __construct(
        private readonly RepositoryPhpFileScanner $scanner = new RepositoryPhpFileScanner(),
        private readonly AstAnalyzer $astAnalyzer = new AstAnalyzer(),
        private readonly DependencyExtractor $dependencyExtractor = new DependencyExtractor(),
    ) {
    }

    public function build(string $rootDirectory): RepositoryIndex
    {
        $files = $this->scanner->scan($rootDirectory);
        $reader = new PhpSourceReader($rootDirectory);

        $symbols = [];
        $symbolsById = [];
        $symbolsByFile = [];
        $parsedFiles = [];
        $dependencyResults = [];
        $dependenciesByFile = [];

        foreach ($files as $relativePath) {
            $source = $reader->read($relativePath);
            if ($source === null) {
                throw new RepositoryIndexException(
                    "Ripple could not analyze PHP file:\n{$relativePath}\n\nReason:\nFile could not be read.",
                );
            }

            $parsed = $this->astAnalyzer->parse($source, $relativePath);
            $parsedFiles[$relativePath] = $parsed;
            $fileSymbols = $parsed->result->symbols;
            foreach ($fileSymbols as $symbol) {
                $this->registerSymbol($symbolsById, $symbol);
                $symbols[] = $symbol;
            }
            $symbolsByFile[$relativePath] = $this->sortSymbols($fileSymbols);

            $fileDependencies = $this->dependencyExtractor->extract($parsed);
            $dependencyResults[] = $fileDependencies;
            $dependenciesByFile[$relativePath] = $fileDependencies->dependencies;
        }

        return new RepositoryIndex(
            phpFiles: $files,
            symbols: $this->sortSymbols($symbols),
            dependencies: DependencyResult::merge($dependencyResults)->dependencies,
            symbolsByFile: $symbolsByFile,
            dependenciesByFile: $dependenciesByFile,
            symbolsById: $symbolsById,
            parsedFiles: $parsedFiles,
        );
    }

    /**
     * @param array<string, Symbol> $symbolsById
     */
    private function registerSymbol(array &$symbolsById, Symbol $symbol): void
    {
        $id = $symbol->fullyQualifiedName;
        if (!isset($symbolsById[$id])) {
            $symbolsById[$id] = $symbol;

            return;
        }

        $files = [$symbolsById[$id]->file, $symbol->file];
        sort($files, SORT_STRING);

        throw new DuplicateSymbolException($id, array_values(array_unique($files)));
    }

    /**
     * @param list<Symbol> $symbols
     * @return list<Symbol>
     */
    private function sortSymbols(array $symbols): array
    {
        usort(
            $symbols,
            static fn (Symbol $left, Symbol $right): int => $left->fullyQualifiedName <=> $right->fullyQualifiedName,
        );

        return $symbols;
    }
}
