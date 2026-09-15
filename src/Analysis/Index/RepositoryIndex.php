<?php

declare(strict_types=1);

namespace Ripple\Analysis\Index;

use Ripple\Analysis\AST\ParsedPhpFile;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\Dependencies\Dependency;

final class RepositoryIndex
{
    /**
     * @param list<string> $phpFiles
     * @param list<Symbol> $symbols
     * @param list<Dependency> $dependencies
     * @param array<string, list<Symbol>> $symbolsByFile
     * @param array<string, list<Dependency>> $dependenciesByFile
     * @param array<string, Symbol> $symbolsById
     * @param array<string, ParsedPhpFile> $parsedFiles
     */
    public function __construct(
        private readonly array $phpFiles,
        private readonly array $symbols,
        private readonly array $dependencies,
        private readonly array $symbolsByFile,
        private readonly array $dependenciesByFile,
        private readonly array $symbolsById,
        private readonly array $parsedFiles = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], [], [], [], []);
    }

    /**
     * @return list<string>
     */
    public function getPhpFiles(): array
    {
        return $this->phpFiles;
    }

    /**
     * @return list<Symbol>
     */
    public function getSymbols(): array
    {
        return $this->symbols;
    }

    /**
     * @return list<Dependency>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function hasSymbol(string $symbolId): bool
    {
        return isset($this->symbolsById[$symbolId]);
    }

    public function getSymbol(string $symbolId): ?Symbol
    {
        return $this->symbolsById[$symbolId] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function getSymbolsForFile(string $file): array
    {
        return $this->symbolsByFile[$file] ?? [];
    }

    /**
     * @return list<Dependency>
     */
    public function getDependenciesForFile(string $file): array
    {
        return $this->dependenciesByFile[$file] ?? [];
    }

    public function phpFileCount(): int
    {
        return count($this->phpFiles);
    }

    public function symbolCount(): int
    {
        return count($this->symbols);
    }

    public function dependencyCount(): int
    {
        return count($this->dependencies);
    }

    /**
     * @return list<ParsedPhpFile>
     */
    public function getParsedFiles(): array
    {
        return array_values($this->parsedFiles);
    }

    public function getParsedFile(string $file): ?ParsedPhpFile
    {
        return $this->parsedFiles[$file] ?? null;
    }
}
