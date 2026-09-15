<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git\History;

use PHPUnit\Framework\TestCase;
use Ripple\Git\History\GitHistoryAnalysisFailed;
use Ripple\Git\History\GitHistoryLogParser;

final class GitHistoryLogParserTest extends TestCase
{
    public function testParsesMachineReadableHistoryRecords(): void
    {
        $output = $this->record(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Alice',
            'alice@example.com',
            '2026-09-14T13:42:10+00:00',
            "10\t2\tsrc/Service.php",
        );
        $output .= $this->record(
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'Bob',
            'bob@example.com',
            '2026-03-01T00:00:00+00:00',
            "5\t1\tsrc/Service.php",
        );

        $commits = (new GitHistoryLogParser())->parse($output);

        $this->assertCount(2, $commits);
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $commits[0]->hash);
        $this->assertSame('Alice <alice@example.com>', $commits[0]->author);
        $this->assertSame('2026-09-14T13:42:10+00:00', $commits[0]->committedAt->format(DATE_ATOM));
        $this->assertSame(10, $commits[0]->linesAdded);
        $this->assertSame(2, $commits[0]->linesDeleted);
        $this->assertSame(5, $commits[1]->linesAdded);
        $this->assertSame(1, $commits[1]->linesDeleted);
    }

    public function testTreatsBinaryNumstatDashesAsZeroLines(): void
    {
        $output = $this->record(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Alice',
            'alice@example.com',
            '2026-09-14T13:42:10+00:00',
            "-\t-\tassets/logo.png",
        );

        $commits = (new GitHistoryLogParser())->parse($output);

        $this->assertCount(1, $commits);
        $this->assertSame(0, $commits[0]->linesAdded);
        $this->assertSame(0, $commits[0]->linesDeleted);
    }

    public function testCountsMergeCommitsWithEmptyNumstatAsZeroLines(): void
    {
        $output = $this->record(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Alice',
            'alice@example.com',
            '2026-09-14T13:42:10+00:00',
            '',
        );

        $commits = (new GitHistoryLogParser())->parse($output);

        $this->assertCount(1, $commits);
        $this->assertSame(0, $commits[0]->linesAdded);
        $this->assertSame(0, $commits[0]->linesDeleted);
    }

    public function testEmptyOutputMeansNoHistory(): void
    {
        $this->assertSame([], (new GitHistoryLogParser())->parse(''));
        $this->assertSame([], (new GitHistoryLogParser())->parse("   \n"));
    }

    public function testRejectsMalformedRecords(): void
    {
        $this->expectException(GitHistoryAnalysisFailed::class);
        $this->expectExceptionMessage('Malformed Git history output.');

        (new GitHistoryLogParser())->parse("not-a-git-log\n10\t2\tfile.php");
    }

    public function testRejectsInvalidTimestamps(): void
    {
        $output = $this->record(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Alice',
            'alice@example.com',
            'not-a-timestamp',
            "1\t0\tfile.php",
        );

        $this->expectException(GitHistoryAnalysisFailed::class);
        $this->expectExceptionMessage('Git did not provide a valid commit timestamp: not-a-timestamp');

        (new GitHistoryLogParser())->parse($output);
    }

    public function testRejectsMalformedNumstatLines(): void
    {
        $output = $this->record(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Alice',
            'alice@example.com',
            '2026-09-14T13:42:10+00:00',
            'not numstat',
        );

        $this->expectException(GitHistoryAnalysisFailed::class);
        $this->expectExceptionMessage('Malformed Git history output.');

        (new GitHistoryLogParser())->parse($output);
    }

    private function record(
        string $hash,
        string $name,
        string $email,
        string $timestamp,
        string $numstat,
    ): string {
        $body = "\x1e{$hash}\x1f{$name}\x1f{$email}\x1f{$timestamp}\n";
        if ($numstat !== '') {
            $body .= $numstat . "\n";
        }

        return $body . "\n";
    }
}
