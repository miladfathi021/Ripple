<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel\Rules;

use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\Laravel\LaravelName;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class LaravelAuthenticationRule implements LaravelSemanticRule
{
    /**
     * @var array<string, true>
     */
    private const AUTH_METHODS = [
        'attempt' => true,
        'user' => true,
        'guard' => true,
        'check' => true,
        'guest' => true,
        'id' => true,
        'login' => true,
        'logout' => true,
        'logoutOtherDevices' => true,
        'validate' => true,
        'once' => true,
        'viaRemember' => true,
        'hasUser' => true,
        'authenticate' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const REQUEST_AUTH_METHODS = [
        'user' => true,
        'authenticate' => true,
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
            if ($call->kind === 'func' && $call->name === 'auth') {
                $annotations[] = $this->annotation($call->containingSymbol);
                continue;
            }

            if ($call->calleeClass === LaravelName::FACADE_AUTH) {
                foreach ($call->chain as $method) {
                    if (isset(self::AUTH_METHODS[$method])) {
                        $annotations[] = $this->annotation($call->containingSymbol);
                        break;
                    }
                }
                continue;
            }

            if ($call->calleeClass === LaravelName::HTTP_REQUEST) {
                foreach ($call->chain as $method) {
                    if (isset(self::REQUEST_AUTH_METHODS[$method])) {
                        $annotations[] = $this->annotation($call->containingSymbol);
                        break;
                    }
                }
            }
        }

        return $annotations;
    }

    private function annotation(string $symbol): SemanticAnnotation
    {
        return new SemanticAnnotation($symbol, FlowSemanticType::Authentication);
    }
}
