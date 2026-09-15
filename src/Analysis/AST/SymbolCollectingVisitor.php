<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;

final class SymbolCollectingVisitor extends NodeVisitorAbstract
{
    /** @var list<string|null> */
    private array $classStack = [];

    /** @var list<string> */
    private array $namespaces = [];

    /** @var list<string> */
    private array $uses = [];

    /** @var list<Symbol> */
    private array $symbols = [];

    public function __construct(
        private readonly string $file,
    ) {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_ && $node->name !== null) {
            $this->namespaces[] = $node->name->toString();

            return null;
        }

        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                $this->uses[] = $use->name->toString();
            }

            return null;
        }

        if ($node instanceof GroupUse) {
            foreach ($node->uses as $use) {
                $this->uses[] = $node->prefix->toString() . '\\' . $use->name->toString();
            }

            return null;
        }

        if ($node instanceof Class_) {
            if ($node->isAnonymous()) {
                $this->classStack[] = null;

                return null;
            }

            $this->pushClassLike($node, SymbolType::Class_);

            return null;
        }

        if ($node instanceof Interface_) {
            $this->pushClassLike($node, SymbolType::Interface);

            return null;
        }

        if ($node instanceof Trait_) {
            $this->pushClassLike($node, SymbolType::Trait);

            return null;
        }

        if ($node instanceof Enum_) {
            $this->pushClassLike($node, SymbolType::Enum);

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->addMethod($node);

            return null;
        }

        if ($node instanceof Function_) {
            $this->addFunction($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if (
            $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_
        ) {
            array_pop($this->classStack);
        }

        return null;
    }

    public function result(): AstResult
    {
        $namespaces = array_values(array_unique($this->namespaces));
        $uses = array_values(array_unique($this->uses));

        return new AstResult(
            file: $this->file,
            namespace: count($namespaces) === 1 ? $namespaces[0] : null,
            uses: $uses,
            symbols: $this->symbols,
        );
    }

    private function pushClassLike(ClassLike $node, SymbolType $type): void
    {
        $name = $node->name?->toString();
        if ($name === null) {
            $this->classStack[] = null;

            return;
        }

        $fqn = $this->namespacedName($node, $name);
        $this->classStack[] = $fqn;
        $this->symbols[] = new Symbol(
            type: $type,
            name: $name,
            fullyQualifiedName: $fqn,
            file: $this->file,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
        );
    }

    private function addMethod(ClassMethod $node): void
    {
        $parent = $this->classStack === [] ? null : $this->classStack[array_key_last($this->classStack)];
        if ($parent === null) {
            return;
        }

        $name = $node->name->toString();
        $this->symbols[] = new Symbol(
            type: SymbolType::Method,
            name: $name,
            fullyQualifiedName: $parent . '::' . $name,
            file: $this->file,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            parent: $parent,
            visibility: $this->visibility($node),
            isStatic: $node->isStatic(),
            attributes: $this->attributeNames($node),
        );
    }

    private function addFunction(Function_ $node): void
    {
        $name = $node->name->toString();
        $fqn = $this->namespacedName($node, $name);
        $this->symbols[] = new Symbol(
            type: SymbolType::Function,
            name: $name,
            fullyQualifiedName: $fqn,
            file: $this->file,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
        );
    }

    private function namespacedName(ClassLike|Function_ $node, string $name): string
    {
        if ($node->namespacedName !== null) {
            return $node->namespacedName->toString();
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private function attributeNames(ClassMethod $node): array
    {
        $names = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $names[] = $this->resolveName($attribute->name);
            }
        }

        return array_values(array_unique($names));
    }

    private function resolveName(Name $name): string
    {
        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof Name) {
            return $namespaced->toString();
        }

        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        return $name->toString();
    }

    private function visibility(ClassMethod $node): string
    {
        if ($node->isPrivate()) {
            return 'private';
        }

        if ($node->isProtected()) {
            return 'protected';
        }

        return 'public';
    }
}
