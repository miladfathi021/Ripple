<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

use Ripple\Analysis\AST\Symbol;

final readonly class TestSymbol
{
    public function __construct(
        public string $id,
        public TestSymbolType $type,
        public string $file,
        public string $classFqn,
        public ?string $methodName,
        public int $startLine,
        public int $endLine,
        public string $testClassFqn,
    ) {
    }

    public static function fromClass(Symbol $symbol): self
    {
        return new self(
            $symbol->fullyQualifiedName,
            TestSymbolType::Class_,
            $symbol->file,
            $symbol->fullyQualifiedName,
            null,
            $symbol->startLine,
            $symbol->endLine,
            $symbol->fullyQualifiedName,
        );
    }

    public static function fromMethod(Symbol $symbol, string $testClassFqn): self
    {
        return new self(
            $symbol->fullyQualifiedName,
            TestSymbolType::Method,
            $symbol->file,
            $testClassFqn,
            $symbol->name,
            $symbol->startLine,
            $symbol->endLine,
            $testClassFqn,
        );
    }
}
