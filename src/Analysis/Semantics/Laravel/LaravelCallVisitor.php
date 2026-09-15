<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;

final class LaravelCallVisitor extends NodeVisitorAbstract
{
    /** @var list<LaravelCallFact> */
    private array $facts = [];

    private ?string $currentClass = null;

    private ?string $currentSymbol = null;

    /** @var array<string, string> */
    private array $localVariables = [];

    /** @var array<string, array<string, string>> */
    private array $propertyTypes = [];

    public function __construct(
        private readonly LaravelSemanticContext $context,
    ) {
    }

    /**
     * @return list<LaravelCallFact>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_ && !$node->isAnonymous()) {
            $this->currentClass = $node->namespacedName?->toString() ?? $node->name?->toString();
            if ($this->currentClass !== null) {
                $this->collectPropertyTypes($node);
            }

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->enterMethod($node);

            return null;
        }

        if ($node instanceof Function_) {
            $this->currentSymbol = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->localVariables = [];
            foreach ($node->params as $parameter) {
                $this->bindParameter($parameter);
            }

            return null;
        }

        if ($node instanceof Assign) {
            $this->recordAssignment($node);

            return null;
        }

        if ($node instanceof StaticCall) {
            $this->recordStaticCall($node);

            return null;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $this->recordMethodCall($node);

            return null;
        }

        if ($node instanceof FuncCall) {
            $this->recordFuncCall($node);

            return null;
        }

        if ($node instanceof New_) {
            $this->recordNew($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->currentSymbol = null;
            $this->localVariables = [];
        }

        if ($node instanceof Class_) {
            $this->currentClass = null;
        }

        return null;
    }

    private function enterMethod(ClassMethod $node): void
    {
        if ($this->currentClass === null) {
            $this->currentSymbol = null;
            $this->localVariables = [];

            return;
        }

        $this->currentSymbol = $this->currentClass . '::' . $node->name->toString();
        $this->localVariables = [];
        foreach ($node->params as $parameter) {
            $this->bindParameter($parameter);
        }
    }

    private function collectPropertyTypes(Class_ $node): void
    {
        $class = $this->currentClass;
        if ($class === null) {
            return;
        }

        foreach ($node->stmts as $statement) {
            if ($statement instanceof Property) {
                $type = $this->namedType($statement->type);
                if ($type === null) {
                    continue;
                }
                foreach ($statement->props as $property) {
                    $this->propertyTypes[$class][$property->name->toString()] = $type;
                }
            }

            if ($statement instanceof ClassMethod && $statement->name->toString() === '__construct') {
                foreach ($statement->params as $parameter) {
                    if ($parameter->flags === 0 || !$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                        continue;
                    }
                    $type = $this->namedType($parameter->type);
                    if ($type !== null) {
                        $this->propertyTypes[$class][$parameter->var->name] = $type;
                    }
                }
            }
        }
    }

    private function bindParameter(mixed $parameter): void
    {
        if (!$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
            return;
        }

        $type = $this->namedType($parameter->type);
        if ($type !== null) {
            $this->localVariables[$parameter->var->name] = $type;
        }
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

    private function recordStaticCall(StaticCall $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }

        $class = LaravelName::className($node->class, $this->context);
        $method = $node->name->toString();
        $this->facts[] = new LaravelCallFact(
            $this->currentSymbol ?? '',
            'static',
            $class,
            $method,
            [$method],
            $node,
        );
    }

    private function recordMethodCall(MethodCall|NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }

        $chain = [];
        $current = $node;
        while ($current instanceof MethodCall || $current instanceof NullsafeMethodCall) {
            if (!$current->name instanceof Identifier) {
                return;
            }
            array_unshift($chain, $current->name->toString());
            $current = $current->var;
        }

        $class = null;
        $kind = 'instance';
        $rootName = $chain[0];
        if ($current instanceof StaticCall && $current->class instanceof Name && $current->name instanceof Identifier) {
            $class = LaravelName::className($current->class, $this->context);
            array_unshift($chain, $current->name->toString());
            $kind = 'static';
            $rootName = $current->name->toString();
        } elseif ($current instanceof New_ && $current->class instanceof Name) {
            $class = LaravelName::className($current->class, $this->context);
            $kind = 'new';
        } elseif ($current instanceof FuncCall && $current->name instanceof Name) {
            $class = $this->helperReturnType($current->name);
            $kind = 'func';
            $rootName = LaravelName::unqualified($current->name) ?? $current->name->toString();
        } else {
            $class = $this->expressionType($current);
        }

        $this->facts[] = new LaravelCallFact(
            $this->currentSymbol ?? '',
            $kind,
            $class,
            $rootName,
            $chain,
            $node,
        );
    }

    private function recordFuncCall(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $name = LaravelName::unqualified($node->name) ?? $node->name->toString();
        $this->facts[] = new LaravelCallFact(
            $this->currentSymbol ?? '',
            'func',
            $this->helperReturnType($node->name),
            $name,
            [$name],
            $node,
        );
    }

    private function recordNew(New_ $node): void
    {
        if (!$node->class instanceof Name) {
            return;
        }

        $class = LaravelName::className($node->class, $this->context);
        $this->facts[] = new LaravelCallFact(
            $this->currentSymbol ?? '',
            'new',
            $class,
            '__construct',
            ['__construct'],
            $node,
        );
    }

    private function helperReturnType(Name $name): ?string
    {
        if (LaravelName::isUnqualifiedHelper($name, 'auth')) {
            return LaravelName::FACADE_AUTH;
        }
        if (LaravelName::isUnqualifiedHelper($name, 'request')) {
            return LaravelName::HTTP_REQUEST;
        }
        if (LaravelName::isUnqualifiedHelper($name, 'event')) {
            return LaravelName::FACADE_EVENT;
        }

        return null;
    }

    private function expressionType(Node $node): ?string
    {
        if ($node instanceof Variable && $node->name === 'this') {
            return $this->currentClass;
        }

        if ($node instanceof Variable && is_string($node->name)) {
            return $this->localVariables[$node->name] ?? null;
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            return LaravelName::className($node->class, $this->context);
        }

        if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
            $owner = $this->expressionType($node->var);
            if ($owner !== null && $owner === $this->currentClass) {
                return $this->propertyTypes[$owner][$node->name->toString()] ?? null;
            }
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $class = LaravelName::className($node->class, $this->context);
            if ($this->context->isEloquentModel($class) || $class === LaravelName::FACADE_DB) {
                return $class;
            }
            if ($class === LaravelName::FACADE_HTTP) {
                return LaravelName::HTTP_CLIENT;
            }
            if ($class === LaravelName::FACADE_AUTH) {
                return LaravelName::FACADE_AUTH;
            }
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $owner = $this->expressionType($node->var);
            if ($owner !== null && (
                $this->context->isEloquentModel($owner)
                || $this->context->isQueryBuilder($owner)
                || $owner === LaravelName::FACADE_DB
                || $owner === LaravelName::FACADE_HTTP
                || $owner === LaravelName::HTTP_CLIENT
                || $owner === LaravelName::GUZZLE_CLIENT
                || $owner === LaravelName::HTTP_REQUEST
                || $owner === LaravelName::FACADE_AUTH
            )) {
                return $owner;
            }
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            return $this->helperReturnType($node->name);
        }

        return null;
    }

    private function namedType(mixed $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->namedType($type->type);
        }

        if ($type instanceof UnionType) {
            return null;
        }

        if ($type instanceof Name) {
            return LaravelName::className($type, $this->context);
        }

        return null;
    }
}
