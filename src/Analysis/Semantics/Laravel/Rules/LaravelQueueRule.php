<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\Laravel\LaravelName;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class LaravelQueueRule implements LaravelSemanticRule
{
    /**
     * @var array<string, true>
     */
    private const DISPATCH_METHODS = [
        'dispatch' => true,
        'dispatchSync' => true,
        'dispatchAfterResponse' => true,
        'push' => true,
        'later' => true,
        'pushOn' => true,
        'laterOn' => true,
    ];

    /**
     * @return list<SemanticAnnotation>
     */
    public function analyze(LaravelSemanticContext $context): array
    {
        $annotations = [];
        foreach ($this->queueClasses($context) as $class) {
            if ($context->hasMethod($class, 'handle')) {
                $annotations[] = $this->annotation($class . '::handle');
            } elseif ($context->hasMethod($class, '__invoke')) {
                $annotations[] = $this->annotation($class . '::__invoke');
            } else {
                $annotations[] = $this->annotation($class);
            }
        }

        foreach ($context->calls() as $call) {
            if ($call->kind === 'static' && isset(self::DISPATCH_METHODS[$call->name]) && $call->calleeClass !== null) {
                if (
                    $call->calleeClass === LaravelName::FACADE_BUS
                    || $call->calleeClass === LaravelName::FACADE_QUEUE
                    || $this->isQueueClass($context, $call->calleeClass)
                ) {
                    if ($call->containingSymbol !== '') {
                        $annotations[] = $this->annotation($call->containingSymbol);
                    }
                    if ($this->isQueueClass($context, $call->calleeClass)) {
                        $annotations[] = $this->annotation($call->calleeClass . '::' . $call->name);
                        if ($context->hasMethod($call->calleeClass, 'handle')) {
                            $annotations[] = $this->annotation($call->calleeClass . '::handle');
                        }
                    }
                }
            }

            if ($call->kind === 'func' && $call->name === 'dispatch') {
                $jobClass = $this->dispatchedClass($call->node, $context);
                if ($jobClass !== null && $this->isQueueClass($context, $jobClass)) {
                    if ($call->containingSymbol !== '') {
                        $annotations[] = $this->annotation($call->containingSymbol);
                    }
                    if ($context->hasMethod($jobClass, 'handle')) {
                        $annotations[] = $this->annotation($jobClass . '::handle');
                    }
                }
            }
        }

        return $annotations;
    }

    /**
     * @return list<string>
     */
    private function queueClasses(LaravelSemanticContext $context): array
    {
        $classes = [];
        foreach ($context->parsedFiles() as $file) {
            foreach ($file->result->symbols as $symbol) {
                if ($symbol->type->value === 'class' && $this->isQueueClass($context, $symbol->fullyQualifiedName)) {
                    $classes[] = $symbol->fullyQualifiedName;
                }
            }
        }

        return $classes;
    }

    private function isQueueClass(LaravelSemanticContext $context, string $class): bool
    {
        return $context->isShouldQueue($class) || $context->usesTrait($class, LaravelName::BUS_DISPATCHABLE);
    }

    private function dispatchedClass(mixed $node, LaravelSemanticContext $context): ?string
    {
        if (!$node instanceof FuncCall || !isset($node->args[0]) || !$node->args[0] instanceof Arg) {
            return null;
        }

        $value = $node->args[0]->value;
        if ($value instanceof New_ && $value->class instanceof Name) {
            return LaravelName::className($value->class, $context);
        }

        return null;
    }

    private function annotation(string $symbol): SemanticAnnotation
    {
        return new SemanticAnnotation($symbol, FlowSemanticType::Queue);
    }
}
