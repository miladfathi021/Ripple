<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

final class StaticSemanticAnnotationProvider implements SemanticAnnotationProvider
{
    /**
     * @param list<SemanticAnnotation> $annotations
     */
    public function __construct(
        private readonly array $annotations = [],
    ) {
    }

    /**
     * @return list<SemanticAnnotation>
     */
    public function getAnnotations(): array
    {
        return $this->annotations;
    }
}
