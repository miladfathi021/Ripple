<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

final readonly class TestImpactResult
{
    /**
     * @param list<TestImpact> $impacts
     */
    public function __construct(
        public array $impacts,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<TestImpact> $impacts
     */
    public static function fromImpacts(array $impacts): self
    {
        $unique = [];
        foreach ($impacts as $impact) {
            $key = $impact->test->id . "\0" . $impact->changedSymbolId;
            if (!isset($unique[$key]) || $impact->depth < $unique[$key]->depth) {
                $unique[$key] = $impact;
            }
        }

        $normalized = array_values($unique);
        usort(
            $normalized,
            static function (TestImpact $left, TestImpact $right): int {
                return [
                    $left->depth,
                    $left->test->file,
                    $left->test->id,
                    $left->changedSymbolId,
                ] <=> [
                    $right->depth,
                    $right->test->file,
                    $right->test->id,
                    $right->changedSymbolId,
                ];
            },
        );

        return new self($normalized);
    }

    public function isEmpty(): bool
    {
        return $this->impacts === [];
    }

    /**
     * Unique test methods, using the shortest depth for each test.
     *
     * @return list<TestImpact>
     */
    public function uniqueTests(): array
    {
        $byTest = [];
        foreach ($this->impacts as $impact) {
            $id = $impact->test->id;
            if (!isset($byTest[$id]) || $impact->depth < $byTest[$id]->depth) {
                $byTest[$id] = $impact;
            }
        }

        $unique = array_values($byTest);
        usort(
            $unique,
            static function (TestImpact $left, TestImpact $right): int {
                return [
                    $left->depth,
                    $left->test->file,
                    $left->test->id,
                ] <=> [
                    $right->depth,
                    $right->test->file,
                    $right->test->id,
                ];
            },
        );

        return $unique;
    }
}
