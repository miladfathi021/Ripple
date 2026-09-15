<?php

declare(strict_types=1);

namespace Ripple\Analysis\Graph;

use Ripple\Analysis\AST\Symbol;

final readonly class GraphNode
{
    public function __construct(
        public string $id,
        public ?string $type,
        public string $name,
        public string $fullyQualifiedName,
        public ?string $file,
        public bool $known,
    ) {
    }

    public static function fromSymbol(Symbol $symbol): self
    {
        return new self(
            id: $symbol->fullyQualifiedName,
            type: $symbol->type->value,
            name: $symbol->name,
            fullyQualifiedName: $symbol->fullyQualifiedName,
            file: $symbol->file,
            known: true,
        );
    }

    public static function unindexed(string $id): self
    {
        return new self(
            id: $id,
            type: null,
            name: self::nameFromId($id),
            fullyQualifiedName: $id,
            file: null,
            known: false,
        );
    }

    private static function nameFromId(string $id): string
    {
        if (str_contains($id, '::')) {
            return substr($id, strrpos($id, '::') + 2);
        }

        $position = strrpos($id, '\\');

        return $position === false ? $id : substr($id, $position + 1);
    }
}
