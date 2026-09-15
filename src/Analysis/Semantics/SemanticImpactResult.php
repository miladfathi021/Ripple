<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

final readonly class SemanticImpactResult
{
    /**
     * @param list<SemanticAnnotation> $blastRadiusAnnotations
     * @param list<SemanticAnnotation> $affectedFlowAnnotations
     */
    public function __construct(
        public array $blastRadiusAnnotations,
        public array $affectedFlowAnnotations,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param list<SemanticAnnotation> $blastRadiusAnnotations
     * @param list<SemanticAnnotation> $affectedFlowAnnotations
     */
    public static function fromAnnotations(array $blastRadiusAnnotations, array $affectedFlowAnnotations): self
    {
        return new self(
            self::uniqueSorted($blastRadiusAnnotations),
            self::uniqueSorted($affectedFlowAnnotations),
        );
    }

    public function isEmpty(): bool
    {
        return $this->blastRadiusAnnotations === [] && $this->affectedFlowAnnotations === [];
    }

    /**
     * @param list<SemanticAnnotation> $annotations
     * @return list<SemanticAnnotation>
     */
    private static function uniqueSorted(array $annotations): array
    {
        $unique = [];
        foreach ($annotations as $annotation) {
            $key = $annotation->symbolId . "\n" . $annotation->type->value;
            if (!isset($unique[$key])) {
                $unique[$key] = $annotation;
            }
        }

        $sorted = array_values($unique);
        usort(
            $sorted,
            static function (SemanticAnnotation $left, SemanticAnnotation $right): int {
                return [$left->type->value, $left->symbolId] <=> [$right->type->value, $right->symbolId];
            },
        );

        return $sorted;
    }
}
