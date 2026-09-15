<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use PhpParser\NodeTraverser;
use Ripple\Analysis\AST\ParsedPhpFile;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Index\RepositoryIndex;

final class LaravelSemanticContext
{
    /**
     * @param list<ParsedPhpFile> $parsedFiles
     * @param array<string, string> $parents
     * @param array<string, list<string>> $interfaces
     * @param array<string, list<string>> $traits
     * @param array<string, list<string>> $publicMethods
     * @param list<LaravelCallFact> $calls
     */
    public function __construct(
        private readonly RepositoryIndex $index,
        private readonly array $parsedFiles,
        private readonly array $parents,
        private readonly array $interfaces,
        private readonly array $traits,
        private readonly array $publicMethods,
        private readonly array $calls,
    ) {
    }

    public static function fromIndex(RepositoryIndex $index): self
    {
        $structure = new LaravelStructureVisitor();
        $structureTraverser = new NodeTraverser($structure);
        foreach ($index->getParsedFiles() as $file) {
            $structureTraverser->traverse($file->statements);
        }

        $context = new self(
            $index,
            $index->getParsedFiles(),
            $structure->parents,
            $structure->interfaces,
            $structure->traits,
            $structure->publicMethods,
            [],
        );

        $calls = new LaravelCallVisitor($context);
        $callTraverser = new NodeTraverser($calls);
        foreach ($index->getParsedFiles() as $file) {
            $callTraverser->traverse($file->statements);
        }

        return new self(
            $index,
            $index->getParsedFiles(),
            $structure->parents,
            $structure->interfaces,
            $structure->traits,
            $structure->publicMethods,
            $calls->facts(),
        );
    }

    /**
     * @return list<ParsedPhpFile>
     */
    public function parsedFiles(): array
    {
        return $this->parsedFiles;
    }

    /**
     * @return list<LaravelCallFact>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function hasClass(string $class): bool
    {
        $symbol = $this->index->getSymbol($class);

        return $symbol !== null && (
            $symbol->type === SymbolType::Class_
            || $symbol->type === SymbolType::Interface
            || $symbol->type === SymbolType::Trait
        );
    }

    public function hasMethod(string $class, string $method): bool
    {
        return $this->index->hasSymbol($class . '::' . $method);
    }

    /**
     * @return list<string>
     */
    public function publicMethods(string $class): array
    {
        return $this->publicMethods[$class] ?? [];
    }

    public function parentOf(string $class): ?string
    {
        return $this->parents[$class] ?? null;
    }

    public function extends(string $class, string $ancestor): bool
    {
        $current = $class;
        $seen = [];
        while ($current !== null && !isset($seen[$current])) {
            if ($current === $ancestor) {
                return true;
            }
            $seen[$current] = true;
            $current = $this->parents[$current] ?? null;
        }

        return false;
    }

    public function implements(string $class, string $interface): bool
    {
        foreach ($this->typesInHierarchy($class) as $type) {
            if ($type === $interface || in_array($interface, $this->interfaces[$type] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function usesTrait(string $class, string $trait): bool
    {
        foreach ($this->typesInHierarchy($class) as $type) {
            if (in_array($trait, $this->traits[$type] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function isLaravelController(string $class): bool
    {
        return $this->extends($class, LaravelName::CONTROLLER);
    }

    public function isEloquentModel(string $class): bool
    {
        return $this->extends($class, LaravelName::MODEL);
    }

    public function isQueryBuilder(string $class): bool
    {
        return $class === LaravelName::BUILDER
            || $class === LaravelName::ELOQUENT_BUILDER
            || $this->extends($class, LaravelName::BUILDER)
            || $this->extends($class, LaravelName::ELOQUENT_BUILDER);
    }

    public function isDatabaseReceiver(string $class): bool
    {
        return $class === LaravelName::FACADE_DB
            || $this->isEloquentModel($class)
            || $this->isQueryBuilder($class);
    }

    public function isShouldQueue(string $class): bool
    {
        return $this->implements($class, LaravelName::SHOULD_QUEUE);
    }

    /**
     * @return list<string>
     */
    private function typesInHierarchy(string $class): array
    {
        $types = [];
        $current = $class;
        $seen = [];
        while ($current !== null && !isset($seen[$current])) {
            $seen[$current] = true;
            $types[] = $current;
            $current = $this->parents[$current] ?? null;
        }

        return $types;
    }
}
