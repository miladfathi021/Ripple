<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git\History;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ripple\Git\History\HistoryCommit;

final class HistoryCommitTest extends TestCase
{
    public function testExposesCommitFacts(): void
    {
        $committedAt = new DateTimeImmutable('2026-09-14T13:42:10+00:00');
        $commit = new HistoryCommit(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Alice <alice@example.com>',
            $committedAt,
            10,
            2,
        );

        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $commit->hash);
        $this->assertSame('Alice <alice@example.com>', $commit->author);
        $this->assertSame($committedAt, $commit->committedAt);
        $this->assertSame(10, $commit->linesAdded);
        $this->assertSame(2, $commit->linesDeleted);
    }
}
