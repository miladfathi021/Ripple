<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitorAbstract;

final class LaravelStructureVisitor extends NodeVisitorAbstract
{
    /** @var array<string, string> */
    public array $parents = [];

    /** @var array<string, list<string>> */
    public array $interfaces = [];

    /** @var array<string, list<string>> */
    public array $traits = [];

    /** @var array<string, list<string>> */
    public array $publicMethods = [];

    private ?string $class = null;

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_ && !$node->isAnonymous()) {
            $this->class = $node->namespacedName?->toString() ?? $node->name?->toString();
            if ($this->class === null) {
                return null;
            }

            if ($node->extends !== null) {
                $this->parents[$this->class] = LaravelName::resolved($node->extends);
            }

            foreach ($node->implements as $interface) {
                $this->interfaces[$this->class][] = LaravelName::resolved($interface);
            }

            return null;
        }

        if ($node instanceof Interface_) {
            $this->class = $node->namespacedName?->toString() ?? $node->name?->toString();
            if ($this->class === null) {
                return null;
            }

            foreach ($node->extends as $interface) {
                $this->interfaces[$this->class][] = LaravelName::resolved($interface);
            }

            return null;
        }

        if ($node instanceof Trait_) {
            $this->class = $node->namespacedName?->toString() ?? $node->name?->toString();

            return null;
        }

        if ($node instanceof TraitUse && $this->class !== null) {
            foreach ($node->traits as $trait) {
                $this->traits[$this->class][] = LaravelName::resolved($trait);
            }

            return null;
        }

        if (
            $node instanceof ClassMethod
            && $this->class !== null
            && $node->isPublic()
            && !$node->isAbstract()
        ) {
            $this->publicMethods[$this->class][] = $node->name->toString();
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if (
            $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
        ) {
            $this->class = null;
        }

        return null;
    }
}
