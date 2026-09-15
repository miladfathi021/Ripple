<?php

declare(strict_types=1);

namespace Ripple\Git\History;

use DateTimeImmutable;

final readonly class HistoryCommit
{
    public function __construct(
        public string $hash,
        public string $author,
        public DateTimeImmutable $committedAt,
        public int $linesAdded,
        public int $linesDeleted,
    ) {
    }
}
