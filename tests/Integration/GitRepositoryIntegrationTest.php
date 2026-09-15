<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\Git\ChangeType;
use Ripple\Git\GitDiffParser;
use Ripple\Git\GitRepository;
use Ripple\Tests\Support\TemporaryGitRepository;

final class GitRepositoryIntegrationTest extends TestCase
{
    public function testParsesATrackedFileChangeFromARealRepository(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile(
            'src/Example.php',
            "line1\nline2\nline3\n",
            'initial',
        );
        $repository->write('src/Example.php', "line1\nchanged\nline3\n");

        $rawDiff = (new GitRepository($repository->path))->workingTreeDiff();
        $result = (new GitDiffParser())->parse($rawDiff);

        $this->assertCount(1, $result->files);
        $file = $result->files[0];
        $this->assertSame('src/Example.php', $file->path);
        $this->assertSame(ChangeType::Modified, $file->changeType);
        $this->assertSame([2], $file->addedLines);
        $this->assertSame([2], $file->deletedLines);
        $this->assertStringNotContainsString('<?php', $rawDiff);
    }

    public function testParsesADeletedTrackedFileFromARealRepository(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('gone.php', "alpha\nbeta\n", 'initial');
        $repository->delete('gone.php');

        $rawDiff = (new GitRepository($repository->path))->workingTreeDiff();
        $result = (new GitDiffParser())->parse($rawDiff);

        $this->assertCount(1, $result->files);
        $file = $result->files[0];
        $this->assertSame('gone.php', $file->path);
        $this->assertSame(ChangeType::Deleted, $file->changeType);
        $this->assertSame([1, 2], $file->deletedLines);
        $this->assertSame([], $file->addedLines);
    }
}
