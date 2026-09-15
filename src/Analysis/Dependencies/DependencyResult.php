<?php

declare(strict_types=1);

namespace Ripple\Analysis\Dependencies;

final readonly class DependencyResult
{
    /**
     * @param list<Dependency> $dependencies
     */
    public function __construct(
        public array $dependencies,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<self> $results
     */
    public static function merge(array $results): self
    {
        $dependencies = [];
        foreach ($results as $result) {
            foreach ($result->dependencies as $dependency) {
                $dependencies[] = $dependency;
            }
        }

        return new self(self::sort($dependencies));
    }

    /**
     * @param list<Dependency> $dependencies
     * @return list<Dependency>
     */
    public static function sort(array $dependencies): array
    {
        usort($dependencies, static function (Dependency $left, Dependency $right): int {
            return [$left->source, $left->target, $left->type->value, $left->line()]
                <=> [$right->source, $right->target, $right->type->value, $right->line()];
        });

        return $dependencies;
    }
}
