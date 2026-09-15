<?php

declare(strict_types=1);

namespace Ripple\Git;

final readonly class DiffResult
{
    /**
     * @param list<ChangedFile> $files
     */
    public function __construct(
        public array $files,
    ) {
    }

    public function fileCount(): int
    {
        return count($this->files);
    }
}
