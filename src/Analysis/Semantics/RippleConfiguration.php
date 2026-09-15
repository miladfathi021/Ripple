<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

final readonly class RippleConfiguration
{
    public const FRAMEWORK_LARAVEL = 'laravel';

    /**
     * @param list<SemanticAnnotation> $semanticAnnotations
     */
    public function __construct(
        public ?string $framework,
        public array $semanticAnnotations,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, []);
    }

    public function usesLaravel(): bool
    {
        return $this->framework === self::FRAMEWORK_LARAVEL;
    }
}
