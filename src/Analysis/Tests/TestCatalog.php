<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

final readonly class TestCatalog
{
    /**
     * @param array<string, TestSymbol> $symbolsById
     */
    private function __construct(
        private array $symbolsById,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<TestSymbol> $symbols
     */
    public static function fromSymbols(array $symbols): self
    {
        $byId = [];
        foreach ($symbols as $symbol) {
            $byId[$symbol->id] = $symbol;
        }
        ksort($byId, SORT_STRING);

        return new self($byId);
    }

    public function isTestSymbol(string $symbolId): bool
    {
        return isset($this->symbolsById[$symbolId]);
    }

    public function isTestMethod(string $symbolId): bool
    {
        $symbol = $this->symbolsById[$symbolId] ?? null;

        return $symbol !== null && $symbol->type === TestSymbolType::Method;
    }

    public function getTestSymbol(string $symbolId): ?TestSymbol
    {
        return $this->symbolsById[$symbolId] ?? null;
    }

    /**
     * @return list<TestSymbol>
     */
    public function all(): array
    {
        return array_values($this->symbolsById);
    }

    public function isEmpty(): bool
    {
        return $this->symbolsById === [];
    }

    public function count(): int
    {
        return count($this->symbolsById);
    }
}
