<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel\Rules;

use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\Laravel\LaravelDatabaseApi;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class LaravelDatabaseWriteRule implements LaravelSemanticRule
{
    /**
     * @return list<SemanticAnnotation>
     */
    public function analyze(LaravelSemanticContext $context): array
    {
        $annotations = [];
        foreach ($context->calls() as $call) {
            if ($call->calleeClass === null || !$context->isDatabaseReceiver($call->calleeClass)) {
                continue;
            }

            foreach ($call->chain as $method) {
                if (LaravelDatabaseApi::isWrite($method) && $call->containingSymbol !== '') {
                    $annotations[] = new SemanticAnnotation($call->containingSymbol, FlowSemanticType::DatabaseWrite);
                    break;
                }
            }
        }

        return $annotations;
    }
}
