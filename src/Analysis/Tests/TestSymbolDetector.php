<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Index\RepositoryIndex;

final class TestSymbolDetector
{
    public const PHPUNIT_TEST_CASE = 'PHPUnit\\Framework\\TestCase';
    public const PHPUNIT_TEST_ATTRIBUTE = 'PHPUnit\\Framework\\Attributes\\Test';

    public function detect(RepositoryIndex $index): TestCatalog
    {
        $parents = $this->parentMap($index);
        $testClasses = [];
        foreach ($index->getSymbols() as $symbol) {
            if ($symbol->type !== SymbolType::Class_) {
                continue;
            }

            if ($this->extendsPhpUnitTestCase($symbol->fullyQualifiedName, $parents)) {
                $testClasses[$symbol->fullyQualifiedName] = $symbol;
            }
        }

        $detected = [];
        foreach ($testClasses as $symbol) {
            $detected[] = TestSymbol::fromClass($symbol);
        }

        foreach ($index->getSymbols() as $symbol) {
            if ($symbol->type !== SymbolType::Method || $symbol->parent === null) {
                continue;
            }

            if (!isset($testClasses[$symbol->parent])) {
                continue;
            }

            if (!$this->isTestMethod($symbol)) {
                continue;
            }

            $detected[] = TestSymbol::fromMethod($symbol, $symbol->parent);
        }

        return TestCatalog::fromSymbols($detected);
    }

    /**
     * @return array<string, string>
     */
    private function parentMap(RepositoryIndex $index): array
    {
        $parents = [];
        foreach ($index->getDependencies() as $dependency) {
            if ($dependency->type !== DependencyType::Extends) {
                continue;
            }

            $source = $index->getSymbol($dependency->source);
            if ($source === null || $source->type !== SymbolType::Class_) {
                continue;
            }

            $parents[$dependency->source] = $dependency->target;
        }

        return $parents;
    }

    /**
     * @param array<string, string> $parents
     */
    private function extendsPhpUnitTestCase(string $classId, array $parents): bool
    {
        $current = $classId;
        $seen = [];
        while ($current !== null && $current !== '' && !isset($seen[$current])) {
            if ($current === self::PHPUNIT_TEST_CASE) {
                return true;
            }

            $seen[$current] = true;
            $current = $parents[$current] ?? null;
        }

        return false;
    }

    private function isTestMethod(Symbol $symbol): bool
    {
        return str_starts_with($symbol->name, 'test')
            || $symbol->hasAttribute(self::PHPUNIT_TEST_ATTRIBUTE);
    }
}
