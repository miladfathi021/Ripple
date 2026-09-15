<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel\Rules;

use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\Laravel\LaravelName;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class LaravelExternalIntegrationRule implements LaravelSemanticRule
{
    /**
     * @var array<string, true>
     */
    private const HTTP_METHODS = [
        'get' => true,
        'post' => true,
        'put' => true,
        'patch' => true,
        'delete' => true,
        'head' => true,
        'options' => true,
        'send' => true,
        'request' => true,
        'sendAsync' => true,
        'requestAsync' => true,
    ];

    /**
     * @return list<SemanticAnnotation>
     */
    public function analyze(LaravelSemanticContext $context): array
    {
        $annotations = [];
        foreach ($context->calls() as $call) {
            if ($call->containingSymbol === '') {
                continue;
            }
            if ($call->kind === 'new' && $call->calleeClass === LaravelName::GUZZLE_CLIENT) {
                $annotations[] = $this->annotation($call->containingSymbol);
                continue;
            }

            if ($call->calleeClass === null) {
                continue;
            }

            if (
                $call->calleeClass !== LaravelName::FACADE_HTTP
                && $call->calleeClass !== LaravelName::HTTP_CLIENT
                && $call->calleeClass !== LaravelName::HTTP_FACTORY
                && $call->calleeClass !== LaravelName::GUZZLE_CLIENT
            ) {
                continue;
            }

            foreach ($call->chain as $method) {
                if (isset(self::HTTP_METHODS[$method])) {
                    $annotations[] = $this->annotation($call->containingSymbol);
                    break;
                }
            }
        }

        return $annotations;
    }

    private function annotation(string $symbol): SemanticAnnotation
    {
        return new SemanticAnnotation($symbol, FlowSemanticType::ExternalIntegration);
    }
}
