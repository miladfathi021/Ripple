<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

interface SemanticAnnotationProvider
{
    /**
     * Explicit semantic annotations keyed by stable graph symbol IDs.
     *
     * @return list<SemanticAnnotation>
     */
    public function getAnnotations(): array;
}
