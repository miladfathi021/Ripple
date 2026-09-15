<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

final readonly class SemanticAnnotation
{
    public function __construct(
        public string $symbolId,
        public FlowSemanticType $type,
    ) {
        if ($symbolId === '' || trim($symbolId) !== $symbolId) {
            throw new InvalidSemanticAnnotationException(
                'Semantic annotation symbol must be a non-empty string.',
            );
        }
    }
}
