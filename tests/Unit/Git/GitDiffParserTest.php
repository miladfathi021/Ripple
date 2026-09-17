<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\DiffResult;
use Ripple\Git\GitDiffParser;

final class GitDiffParserTest extends TestCase
{
    public function testParsesAModifiedFile(): void
    {
        $file = $this->singleFile('modified.diff');

        $this->assertSame('src.php', $file->path);
        $this->assertSame(ChangeType::Modified, $file->changeType);
        $this->assertSame([10, 11], $file->addedLines);
        $this->assertSame([10], $file->deletedLines);
    }

    public function testParsesAnAddedFile(): void
    {
        $file = $this->singleFile('added.diff');

        $this->assertSame('NewService.php', $file->path);
        $this->assertSame(ChangeType::Added, $file->changeType);
        $this->assertSame([1, 2], $file->addedLines);
        $this->assertSame([], $file->deletedLines);
    }

    public function testParsesADeletedFile(): void
    {
        $file = $this->singleFile('deleted.diff');

        $this->assertSame('gone.php', $file->path);
        $this->assertSame(ChangeType::Deleted, $file->changeType);
        $this->assertSame([], $file->addedLines);
        $this->assertSame([1, 2, 3], $file->deletedLines);
    }

    public function testParsesARenamedFile(): void
    {
        $file = $this->singleFile('renamed.diff');

        $this->assertSame('new.php', $file->path);
        $this->assertSame('old.php', $file->oldPath);
        $this->assertSame(ChangeType::Renamed, $file->changeType);
        $this->assertSame([], $file->addedLines);
        $this->assertSame([], $file->deletedLines);
    }

    public function testParsesARenameWithChanges(): void
    {
        $file = $this->singleFile('renamed-with-changes.diff');

        $this->assertSame('new.php', $file->path);
        $this->assertSame(ChangeType::Renamed, $file->changeType);
        $this->assertSame([2, 3], $file->addedLines);
        $this->assertSame([2], $file->deletedLines);
    }

    public function testParsesMultipleHunksInOneFile(): void
    {
        $file = $this->singleFile('multiple-hunks.diff');

        $this->assertSame('multi.php', $file->path);
        $this->assertSame([3, 15], $file->addedLines);
        $this->assertSame([3, 15], $file->deletedLines);
    }

    public function testParsesMultipleFiles(): void
    {
        $result = $this->parseFixture('multiple-files.diff');

        $this->assertCount(2, $result->files);
        $this->assertSame('a.php', $result->files[0]->path);
        $this->assertSame([1], $result->files[0]->addedLines);
        $this->assertSame([1], $result->files[0]->deletedLines);
        $this->assertSame('b.php', $result->files[1]->path);
        $this->assertSame([1], $result->files[1]->addedLines);
        $this->assertSame([1], $result->files[1]->deletedLines);
    }

    public function testParsesAddedAndDeletedLinesInTheSameHunk(): void
    {
        $file = $this->singleFile('mixed-hunk.diff');

        $this->assertSame([2, 3], $file->addedLines);
        $this->assertSame([2], $file->deletedLines);
    }

    public function testParsesASingleLineHunk(): void
    {
        $file = $this->singleFile('single-line-hunk.diff');

        $this->assertSame(ChangeType::Modified, $file->changeType);
        $this->assertSame([1], $file->addedLines);
        $this->assertSame([1], $file->deletedLines);
    }

    public function testIgnoresNoNewlineAtEndOfFileMarkers(): void
    {
        $file = $this->singleFile('no-newline.diff');

        $this->assertSame([2], $file->addedLines);
        $this->assertSame([2], $file->deletedLines);
    }

    public function testParsesAnEmptyAddedFileWithoutHunks(): void
    {
        $file = $this->singleFile('empty-added.diff');

        $this->assertSame('empty.txt', $file->path);
        $this->assertSame(ChangeType::Added, $file->changeType);
        $this->assertSame([], $file->addedLines);
        $this->assertSame([], $file->deletedLines);
    }

    public function testCalculatesHunkLineNumbersIndependently(): void
    {
        $file = $this->singleFile('hunk-line-numbers.diff');

        $this->assertSame([11, 12], $file->addedLines);
        $this->assertSame([11], $file->deletedLines);
    }

    public function testParsesAnEmptyDiff(): void
    {
        $result = (new GitDiffParser())->parse('');

        $this->assertSame([], $result->files);
    }

    public function testParsesQuotedPathsWithSpaces(): void
    {
        $diff = <<<'DIFF'
diff --git "a/src/My File.php" "b/src/My File.php"
index 1111111..2222222 100644
--- "a/src/My File.php"
+++ "b/src/My File.php"
@@ -1,1 +1,1 @@
-old
+new
DIFF;

        $file = (new GitDiffParser())->parse($diff)->files[0];

        $this->assertSame('src/My File.php', $file->path);
        $this->assertSame(ChangeType::Modified, $file->changeType);
        $this->assertSame([1], $file->addedLines);
        $this->assertSame([1], $file->deletedLines);
    }

    public function testParsesBinaryFilesWithoutInventingLineNumbers(): void
    {
        $diff = <<<'DIFF'
diff --git a/assets/logo.png b/assets/logo.png
index 1111111..2222222 100644
Binary files a/assets/logo.png and b/assets/logo.png differ
DIFF;

        $file = (new GitDiffParser())->parse($diff)->files[0];

        $this->assertSame('assets/logo.png', $file->path);
        $this->assertSame(ChangeType::Modified, $file->changeType);
        $this->assertSame([], $file->addedLines);
        $this->assertSame([], $file->deletedLines);
    }

    private function singleFile(string $fixture): ChangedFile
    {
        $result = $this->parseFixture($fixture);

        $this->assertCount(1, $result->files);

        return $result->files[0];
    }

    private function parseFixture(string $fixture): DiffResult
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/diffs/' . $fixture;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return (new GitDiffParser())->parse($contents);
    }
}
