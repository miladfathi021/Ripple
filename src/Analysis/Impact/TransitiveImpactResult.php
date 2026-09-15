<?php

declare(strict_types=1);

namespace Ripple\Analysis\Impact;

final readonly class TransitiveImpactResult
{
    /**
     * @param list<BlastRadiusEntry> $entries
     */
    public function __construct(
        public array $entries,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<BlastRadiusEntry> $entries
     */
    public static function fromEntries(array $entries): self
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $origins = $entry->origins;
            usort(
                $origins,
                static function (BlastRadiusOrigin $left, BlastRadiusOrigin $right): int {
                    return [
                        $left->changedSymbolId,
                        $left->depth,
                        $left->edge->type->value,
                        $left->edge->source,
                        $left->edge->target,
                        $left->edge->lines[0] ?? 0,
                    ] <=> [
                        $right->changedSymbolId,
                        $right->depth,
                        $right->edge->type->value,
                        $right->edge->source,
                        $right->edge->target,
                        $right->edge->lines[0] ?? 0,
                    ];
                },
            );
            $normalized[] = new BlastRadiusEntry($entry->impactedSymbolId, $entry->depth, $origins);
        }

        usort(
            $normalized,
            static function (BlastRadiusEntry $left, BlastRadiusEntry $right): int {
                return [$left->depth, $left->impactedSymbolId] <=> [$right->depth, $right->impactedSymbolId];
            },
        );

        return new self($normalized);
    }

    /**
     * Unique impacted symbol IDs in result order (min depth, then ID).
     *
     * @return list<string>
     */
    public function getImpactedSymbolIds(): array
    {
        return array_map(
            static fn (BlastRadiusEntry $entry): string => $entry->impactedSymbolId,
            $this->entries,
        );
    }

    public function getEntry(string $impactedSymbolId): ?BlastRadiusEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->impactedSymbolId === $impactedSymbolId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<BlastRadiusEntry>
     */
    public function getEntriesAtDepth(int $depth): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            if ($entry->depth === $depth) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
