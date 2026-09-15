<?php

declare(strict_types=1);

namespace Ripple\Git\History;

use DateTimeImmutable;

final readonly class FileChurn
{
    public function __construct(
        public string $file,
        public int $commitCount,
        public int $linesAdded,
        public int $linesDeleted,
        public int $contributorsCount,
        public ?DateTimeImmutable $lastChangedAt,
    ) {
    }

    public static function none(string $file): self
    {
        return new self($file, 0, 0, 0, 0, null);
    }
}
