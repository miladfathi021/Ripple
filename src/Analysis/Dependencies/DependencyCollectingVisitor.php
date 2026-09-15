<?php

declare(strict_types=1);

namespace Ripple\Analysis\Dependencies;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;
use Ripple\Analysis\AST\Symbol;

final class DependencyCollectingVisitor extends NodeVisitorAbstract
{
    /** @var array<string, array{source: string, target: string, type: DependencyType, lines: list<int>}> */
    private array $grouped = [];

    /** @var list<array{class: ?string, parent: ?string, properties: array<string, string>}> */
    private array $classStack = [];

    private ?string $currentMethod = null;

    /** @var array<string, string> */
    private array $localVariables = [];

    /** @var array<string, true> */
    private array $constructorSymbols = [];

    /**
     * @param list<Symbol> $symbols
     */
    public function __construct(array $symbols)
    {
        foreach ($symbols as $symbol) {
            if (str_ends_with($symbol->fullyQualifiedName, '::__construct')) {
                $this->constructorSymbols[$symbol->fullyQualifiedName] = true;
            }
        }
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_) {
            if ($node->isAnonymous()) {
                $this->pushClassContext(null, null);

                return null;
            }

            $this->enterClassLike($node, $node->extends, $node->implements);
            $this->collectProperties($node);

            return null;
        }

        if ($node instanceof Interface_) {
            $this->enterClassLike($node, null, []);
            foreach ($node->extends as $extended) {
                $this->addNameDependency($extended, DependencyType::Extends, $node->getStartLine());
            }

            return null;
        }

        if ($node instanceof Trait_) {
            $this->enterClassLike($node, null, []);

            return null;
        }

        if ($node instanceof Enum_) {
            $this->enterClassLike($node, null, $node->implements);

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->enterMethod($node);

            return null;
        }

        if ($node instanceof Function_) {
            $this->enterFunction($node);

            return null;
        }

        if ($this->source() === null) {
            return null;
        }

        if ($node instanceof TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addNameDependency($trait, DependencyType::TraitUse, $node->getStartLine());
            }

            return null;
        }

        if ($node instanceof Property) {
            $this->addTypeDependencies($node->type, DependencyType::PropertyType, $node->getStartLine());

            return null;
        }

        if ($node instanceof Assign) {
            $this->recordAssignment($node);

            return null;
        }

        if ($node instanceof New_) {
            $this->addConstructorCall($node);

            return null;
        }

        if ($node instanceof StaticCall) {
            $this->addStaticCall($node);

            return null;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $this->addMethodCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->currentMethod = null;
            $this->localVariables = [];
        }

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

    /**
     * @return list<Dependency>
     */
    public function dependencies(): array
    {
        $dependencies = [];
        foreach ($this->grouped as $item) {
            $lines = $item['lines'];
            sort($lines, SORT_NUMERIC);
            $dependencies[] = new Dependency(
                source: $item['source'],
                target: $item['target'],
                type: $item['type'],
                lines: array_values(array_unique($lines)),
                occurrences: count($item['lines']),
            );
        }

        return $dependencies;
    }

    /**
     * @param Name[] $implements
     */
    private function enterClassLike(ClassLike $node, ?Name $extends, array $implements): void
    {
        $name = $node->name?->toString();
        if ($name === null) {
            $this->pushClassContext(null, null);

            return;
        }

        $class = $node->namespacedName?->toString() ?? $name;
        $parent = $extends instanceof Name ? $this->resolveName($extends) : null;
        $this->pushClassContext($class, $parent);

        if ($extends instanceof Name) {
            $this->addNameDependency($extends, DependencyType::Extends, $node->getStartLine());
        }

        foreach ($implements as $interface) {
            $this->addNameDependency($interface, DependencyType::Implements, $node->getStartLine());
        }
    }

    private function collectProperties(ClassLike $node): void
    {
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Property) {
                $types = $this->namedTypes($statement->type);
                if (count($types) !== 1) {
                    continue;
                }

                foreach ($statement->props as $property) {
                    $this->setPropertyType($property->name->toString(), $types[0]);
                }
            }

            if (
                $statement instanceof ClassMethod
                && $statement->name->toString() === '__construct'
            ) {
                foreach ($statement->params as $parameter) {
                    if ($parameter->flags === 0 || !$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                        continue;
                    }

                    $types = $this->namedTypes($parameter->type);
                    if (count($types) === 1) {
                        $this->setPropertyType($parameter->var->name, $types[0]);
                    }
                }
            }
        }
    }

    private function enterMethod(ClassMethod $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            $this->currentMethod = null;
            $this->localVariables = [];

            return;
        }

        $this->currentMethod = $class . '::' . $node->name->toString();
        $this->localVariables = [];
        $this->addTypeDependencies($node->returnType, DependencyType::ReturnType, $node->getStartLine());

        foreach ($node->params as $parameter) {
            $this->addTypeDependencies($parameter->type, DependencyType::ParameterType, $parameter->getStartLine());
            if ($parameter->flags !== 0) {
                $this->addTypeDependencies($parameter->type, DependencyType::PropertyType, $parameter->getStartLine(), $class);
            }
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types = $this->namedTypes($parameter->type);
                if (count($types) === 1) {
                    $this->localVariables[$parameter->var->name] = $types[0];
                }
            }
        }
    }

    private function enterFunction(Function_ $node): void
    {
        $this->currentMethod = $node->namespacedName?->toString() ?? $node->name->toString();
        $this->localVariables = [];
        $this->addTypeDependencies($node->returnType, DependencyType::ReturnType, $node->getStartLine());

        foreach ($node->params as $parameter) {
            $this->addTypeDependencies($parameter->type, DependencyType::ParameterType, $parameter->getStartLine());
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types = $this->namedTypes($parameter->type);
                if (count($types) === 1) {
                    $this->localVariables[$parameter->var->name] = $types[0];
                }
            }
        }
    }

    private function addConstructorCall(New_ $node): void
    {
        if (!$node->class instanceof Name) {
            return;
        }

        $class = $this->resolveName($node->class);
        if ($class === null) {
            return;
        }

        $constructor = $class . '::__construct';
        $target = isset($this->constructorSymbols[$constructor]) ? $constructor : $class;
        $this->add($target, DependencyType::ConstructorCall, $node->getStartLine());
    }

    private function addStaticCall(StaticCall $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }

        $class = $this->resolveName($node->class);
        if ($class === null) {
            return;
        }

        $this->add($class . '::' . $node->name->toString(), DependencyType::StaticCall, $node->getStartLine());
    }

    private function addMethodCall(MethodCall|NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }

        $type = $this->expressionType($node->var);
        if ($type === null) {
            return;
        }

        $this->add($type . '::' . $node->name->toString(), DependencyType::MethodCall, $node->getStartLine());
    }

    private function recordAssignment(Assign $node): void
    {
        if (!$node->var instanceof Variable || !is_string($node->var->name)) {
            return;
        }

        $type = $this->expressionType($node->expr);
        if ($type !== null) {
            $this->localVariables[$node->var->name] = $type;
        }
    }

    private function expressionType(Node $node): ?string
    {
        if ($node instanceof Variable && $node->name === 'this') {
            return $this->currentClass();
        }

        if ($node instanceof Variable && is_string($node->name)) {
            return $this->localVariables[$node->name] ?? null;
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            return $this->resolveName($node->class);
        }

        if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
            $owner = $this->expressionType($node->var);
            if ($owner !== null && $owner === $this->currentClass()) {
                return $this->propertyType($node->name->toString());
            }
        }

        return null;
    }

    private function addNameDependency(Name $name, DependencyType $type, int $line): void
    {
        $resolved = $this->resolveName($name);
        if ($resolved !== null) {
            $this->add($resolved, $type, $line);
        }
    }

    private function addTypeDependencies(
        ?Node $type,
        DependencyType $dependencyType,
        int $line,
        ?string $source = null,
    ): void {
        foreach ($this->namedTypes($type) as $namedType) {
            $this->add($namedType, $dependencyType, $line, $source);
        }
    }

    /**
     * @return list<string>
     */
    private function namedTypes(?Node $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof NullableType) {
            return $this->namedTypes($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];
            foreach ($type->types as $part) {
                foreach ($this->namedTypes($part) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        if ($type instanceof Identifier) {
            return $this->specialIdentifier($type->toString());
        }

        if ($type instanceof Name) {
            $resolved = $this->resolveName($type);

            return $resolved === null ? [] : [$resolved];
        }

        if ($type instanceof ComplexType) {
            return [];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function specialIdentifier(string $name): array
    {
        $normalized = strtolower($name);
        if ($normalized === 'self' || $normalized === 'static') {
            $class = $this->currentClass();

            return $class === null ? [] : [$class];
        }

        if ($normalized === 'parent') {
            $parent = $this->currentParent();

            return $parent === null ? [] : [$parent];
        }

        return [];
    }

    private function resolveName(Name $name): ?string
    {
        $value = $name->toString();
        $normalized = strtolower($value);

        if ($normalized === 'self' || $normalized === 'static') {
            return $this->currentClass();
        }

        if ($normalized === 'parent') {
            return $this->currentParent();
        }

        return $value;
    }

    private function add(string $target, DependencyType $type, int $line, ?string $source = null): void
    {
        $sourceSymbol = $source ?? $this->source();
        if ($sourceSymbol === null || $target === '') {
            return;
        }

        $key = $sourceSymbol . "\0" . $target . "\0" . $type->value;
        if (!isset($this->grouped[$key])) {
            $this->grouped[$key] = [
                'source' => $sourceSymbol,
                'target' => $target,
                'type' => $type,
                'lines' => [],
            ];
        }

        $this->grouped[$key]['lines'][] = $line;
    }

    private function source(): ?string
    {
        return $this->currentMethod ?? $this->currentClass();
    }

    /**
     * @param array<string, string> $properties
     */
    private function pushClassContext(?string $class, ?string $parent, array $properties = []): void
    {
        $this->classStack[] = [
            'class' => $class,
            'parent' => $parent,
            'properties' => $properties,
        ];
    }

    private function currentClass(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)]['class'];
    }

    private function currentParent(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)]['parent'];
    }

    private function setPropertyType(string $name, string $type): void
    {
        $index = array_key_last($this->classStack);
        if ($index === null) {
            return;
        }

        $this->classStack[$index]['properties'][$name] = $type;
    }

    private function propertyType(string $name): ?string
    {
        $index = array_key_last($this->classStack);
        if ($index === null) {
            return null;
        }

        return $this->classStack[$index]['properties'][$name] ?? null;
    }
}
