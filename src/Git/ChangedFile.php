<?php

declare(strict_types=1);

namespace Ripple\Git;

final readonly class ChangedFile
{
    /**
     * @param list<int> $addedLines
     * @param list<int> $deletedLines
     */
    public function __construct(
        public string $path,
        public ChangeType $changeType,
        public array $addedLines,
        public array $deletedLines,
        public ?string $oldPath = null,
    ) {
    }
}
