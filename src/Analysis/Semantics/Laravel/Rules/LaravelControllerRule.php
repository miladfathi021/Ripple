<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\Laravel\LaravelCallFact;
use Ripple\Analysis\Semantics\Laravel\LaravelName;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class LaravelControllerRule implements LaravelSemanticRule
{
    /**
     * @var array<string, true>
     */
    private const ROUTE_REGISTRATION = [
        'get' => true,
        'post' => true,
        'put' => true,
        'patch' => true,
        'delete' => true,
        'options' => true,
        'any' => true,
        'match' => true,
        'resource' => true,
        'apiResource' => true,
        'controller' => true,
        'action' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const NON_ACTIONS = [
        '__construct' => true,
        '__destruct' => true,
        'middleware' => true,
        'getMiddleware' => true,
        'callAction' => true,
    ];

    /**
     * @return list<SemanticAnnotation>
     */
    public function analyze(LaravelSemanticContext $context): array
    {
        $annotations = [];
        foreach ($this->controllerClasses($context) as $class) {
            foreach ($context->publicMethods($class) as $method) {
                if ($this->isAction($method)) {
                    $annotations[] = $this->annotation($class . '::' . $method);
                }
            }
        }

        foreach ($context->calls() as $call) {
            if ($call->calleeClass !== LaravelName::FACADE_ROUTE || $call->kind !== 'static') {
                continue;
            }
            if (!isset(self::ROUTE_REGISTRATION[$call->name])) {
                continue;
            }
            foreach ($this->routeTargets($context, $call) as $symbol) {
                $annotations[] = $this->annotation($symbol);
            }
        }

        return $annotations;
    }

    /**
     * @return list<string>
     */
    private function controllerClasses(LaravelSemanticContext $context): array
    {
        $classes = [];
        foreach ($context->parsedFiles() as $file) {
            foreach ($file->result->symbols as $symbol) {
                if ($symbol->type->value === 'class' && $context->isLaravelController($symbol->fullyQualifiedName)) {
                    $classes[] = $symbol->fullyQualifiedName;
                }
            }
        }

        return $classes;
    }

    private function isAction(string $method): bool
    {
        if (isset(self::NON_ACTIONS[$method])) {
            return false;
        }

        return !str_starts_with($method, '__') || $method === '__invoke';
    }

    /**
     * @return list<string>
     */
    private function routeTargets(LaravelSemanticContext $context, LaravelCallFact $call): array
    {
        if (!$call->node instanceof StaticCall) {
            return [];
        }

        $arguments = $call->node->args;
        $actionArg = match ($call->name) {
            'resource', 'apiResource', 'controller' => $arguments[1] ?? $arguments[0] ?? null,
            'match' => $arguments[2] ?? null,
            default => $arguments[1] ?? null,
        };

        if (!$actionArg instanceof Arg) {
            return [];
        }

        return $this->actionSymbols($context, $actionArg->value);
    }

    /**
     * @return list<string>
     */
    private function actionSymbols(LaravelSemanticContext $context, mixed $value): array
    {
        if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
            $class = LaravelName::className($value->class, $context);
            if ($context->hasMethod($class, '__invoke')) {
                return [$class . '::__invoke'];
            }

            return [$class];
        }

        if ($value instanceof String_ && str_contains($value->value, '@')) {
            [$class, $method] = explode('@', $value->value, 2);

            return [$class . '::' . $method];
        }

        if ($value instanceof Array_ && isset($value->items[0]) && $value->items[0] instanceof ArrayItem) {
            $classItem = $value->items[0]->value;
            if (!$classItem instanceof ClassConstFetch || !$classItem->class instanceof Name) {
                return [];
            }
            $class = LaravelName::className($classItem->class, $context);
            if (!isset($value->items[1]) || !$value->items[1] instanceof ArrayItem) {
                if ($context->hasMethod($class, '__invoke')) {
                    return [$class . '::__invoke'];
                }

                return [$class];
            }
            $methodItem = $value->items[1]->value;
            if ($methodItem instanceof String_) {
                return [$class . '::' . $methodItem->value];
            }
            if ($methodItem instanceof Identifier) {
                return [$class . '::' . $methodItem->toString()];
            }
        }

        return [];
    }

    private function annotation(string $symbol): SemanticAnnotation
    {
        return new SemanticAnnotation($symbol, FlowSemanticType::ApiEntrypoint);
    }
}
