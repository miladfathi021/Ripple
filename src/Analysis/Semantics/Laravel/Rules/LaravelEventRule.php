<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\Laravel\LaravelCallFact;
use Ripple\Analysis\Semantics\Laravel\LaravelName;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class LaravelEventRule implements LaravelSemanticRule
{
    /**
     * @return list<SemanticAnnotation>
     */
    public function analyze(LaravelSemanticContext $context): array
    {
        $annotations = [];
        foreach ($context->parsedFiles() as $file) {
            foreach ($file->result->symbols as $symbol) {
                if ($symbol->type->value === 'class' && $context->usesTrait($symbol->fullyQualifiedName, LaravelName::EVENT_DISPATCHABLE)) {
                    $annotations[] = $this->annotation($symbol->fullyQualifiedName);
                }
            }
        }

        foreach ($context->calls() as $call) {
            if ($call->kind === 'func' && $call->name === 'event') {
                if ($call->containingSymbol !== '') {
                    $annotations[] = $this->annotation($call->containingSymbol);
                }
                continue;
            }

            if ($call->calleeClass !== LaravelName::FACADE_EVENT || $call->kind !== 'static') {
                continue;
            }

            if (in_array($call->name, ['dispatch', 'dispatchNow', 'until'], true) && $call->containingSymbol !== '') {
                $annotations[] = $this->annotation($call->containingSymbol);
            }

            if ($call->name === 'listen') {
                if ($call->containingSymbol !== '') {
                    $annotations[] = $this->annotation($call->containingSymbol);
                }
                foreach ($this->listenTargets($context, $call) as $symbol) {
                    $annotations[] = $this->annotation($symbol);
                }
            }
        }

        return $annotations;
    }

    /**
     * @return list<string>
     */
    private function listenTargets(LaravelSemanticContext $context, LaravelCallFact $call): array
    {
        if (!$call->node instanceof StaticCall || !isset($call->node->args[1]) || !$call->node->args[1] instanceof Arg) {
            return [];
        }

        $value = $call->node->args[1]->value;
        if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
            $class = LaravelName::className($value->class, $context);
            if ($context->hasMethod($class, 'handle')) {
                return [$class . '::handle'];
            }

            return [$class];
        }

        if ($value instanceof Array_ && isset($value->items[0], $value->items[1])
            && $value->items[0] instanceof ArrayItem
            && $value->items[1] instanceof ArrayItem
            && $value->items[0]->value instanceof ClassConstFetch
            && $value->items[0]->value->class instanceof Name
            && $value->items[1]->value instanceof String_
        ) {
            $class = LaravelName::className($value->items[0]->value->class, $context);

            return [$class . '::' . $value->items[1]->value->value];
        }

        return [];
    }

    private function annotation(string $symbol): SemanticAnnotation
    {
        return new SemanticAnnotation($symbol, FlowSemanticType::Event);
    }
}
