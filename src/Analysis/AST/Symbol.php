<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

final readonly class Symbol
{
    public function __construct(
        public SymbolType $type,
        public string $name,
        public string $fullyQualifiedName,
        public string $file,
        public int $startLine,
        public int $endLine,
        public ?string $parent = null,
        public ?string $visibility = null,
        public ?bool $isStatic = null,
        /** @var list<string> */
        public array $attributes = [],
    ) {
    }

    public function hasAttribute(string $name): bool
    {
        return in_array($name, $this->attributes, true);
    }
}
