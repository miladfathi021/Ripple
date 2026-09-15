<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use Ripple\Analysis\Semantics\SemanticAnnotation;

interface LaravelSemanticRule
{
    /**
     * @return list<SemanticAnnotation>
     */
    public function analyze(LaravelSemanticContext $context): array;
}
