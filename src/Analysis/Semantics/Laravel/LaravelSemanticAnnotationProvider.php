<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use Ripple\Analysis\Index\RepositoryIndexHolder;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelAuthenticationRule;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelControllerRule;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelDatabaseReadRule;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelDatabaseWriteRule;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelEventRule;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelExternalIntegrationRule;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelQueueRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\SemanticAnnotationProvider;

final class LaravelSemanticAnnotationProvider implements SemanticAnnotationProvider
{
    /** @var list<LaravelSemanticRule> */
    private readonly array $rules;

    /**
     * @param list<LaravelSemanticRule>|null $rules
     */
    public function __construct(
        private readonly RepositoryIndexHolder $indexHolder,
        ?array $rules = null,
    ) {
        $this->rules = $rules ?? self::defaultRules();
    }

    /**
     * @return list<LaravelSemanticRule>
     */
    public static function defaultRules(): array
    {
        return [
            new LaravelControllerRule(),
            new LaravelDatabaseReadRule(),
            new LaravelDatabaseWriteRule(),
            new LaravelQueueRule(),
            new LaravelEventRule(),
            new LaravelAuthenticationRule(),
            new LaravelExternalIntegrationRule(),
        ];
    }

    /**
     * @return list<SemanticAnnotation>
     */
    public function getAnnotations(): array
    {
        $index = $this->indexHolder->get();
        if ($index === null) {
            return [];
        }

        $context = LaravelSemanticContext::fromIndex($index);
        $unique = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->analyze($context) as $annotation) {
                $key = $annotation->symbolId . "\n" . $annotation->type->value;
                if (!isset($unique[$key])) {
                    $unique[$key] = $annotation;
                }
            }
        }

        $annotations = array_values($unique);
        usort(
            $annotations,
            static function (SemanticAnnotation $left, SemanticAnnotation $right): int {
                return [$left->symbolId, $left->type->value] <=> [$right->symbolId, $right->type->value];
            },
        );

        return $annotations;
    }
}
