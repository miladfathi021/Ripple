<?php

declare(strict_types=1);

namespace Ripple\Git\History;

final readonly class ChurnResult
{
    /**
     * @param list<FileChurn> $files
     */
    public function __construct(
        public array $files,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<FileChurn> $files
     */
    public static function fromFiles(array $files): self
    {
        usort(
            $files,
            static fn (FileChurn $left, FileChurn $right): int => $left->file <=> $right->file,
        );

        return new self($files);
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    public function get(string $file): ?FileChurn
    {
        foreach ($this->files as $churn) {
            if ($churn->file === $file) {
                return $churn;
            }
        }

        return null;
    }
}
