<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

final class CompositeSemanticAnnotationProvider implements SemanticAnnotationProvider
{
    /**
     * @param list<SemanticAnnotationProvider> $providers Earlier providers win on duplicate (symbol, type) pairs.
     */
    public function __construct(
        private readonly array $providers,
    ) {
    }

    /**
     * @return list<SemanticAnnotation>
     */
    public function getAnnotations(): array
    {
        $unique = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getAnnotations() as $annotation) {
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
