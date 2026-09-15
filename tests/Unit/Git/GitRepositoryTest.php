<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Ripple\Git\GitRepository;
use Ripple\Git\NotAGitRepository;
use Ripple\Tests\Support\TemporaryGitRepository;

final class GitRepositoryTest extends TestCase
{
    public function testDetectsAGitRepository(): void
    {
        $repository = TemporaryGitRepository::create();

        $git = new GitRepository($repository->path);

        $this->assertTrue($git->isInsideWorkTree());
    }

    public function testDetectsADirectoryThatIsNotAGitRepository(): void
    {
        $directory = TemporaryGitRepository::emptyDirectory();

        $git = new GitRepository($directory);

        $this->assertFalse($git->isInsideWorkTree());
    }

    public function testWorkingTreeDiffFailsOutsideAGitRepository(): void
    {
        $directory = TemporaryGitRepository::emptyDirectory();
        $git = new GitRepository($directory);

        $this->expectException(NotAGitRepository::class);
        $this->expectExceptionMessage('not a Git repository.');

        $git->workingTreeDiff();
    }

    public function testWorkingTreeDiffDoesNotExposeProcessOutput(): void
    {
        $directory = TemporaryGitRepository::emptyDirectory();
        $git = new GitRepository($directory);

        try {
            $git->workingTreeDiff();
            $this->fail('Expected NotAGitRepository to be thrown.');
        } catch (NotAGitRepository $exception) {
            $this->assertStringNotContainsString('fatal:', $exception->getMessage());
            $this->assertStringNotContainsString('stderr', $exception->getMessage());
        }
    }

    public function testFileHistoryFailsOutsideAGitRepository(): void
    {
        $directory = TemporaryGitRepository::emptyDirectory();
        $git = new GitRepository($directory);

        $this->expectException(NotAGitRepository::class);
        $this->expectExceptionMessage('not a Git repository.');

        $git->fileHistory('src/Example.php');
    }

    public function testFileHistoryReturnsMachineReadableCommitsForAFile(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', "<?php\n", 'initial');

        $output = (new GitRepository($repository->path))->fileHistory('src/Example.php', followRenames: true);

        $this->assertStringContainsString("\x1e", $output);
        $this->assertStringContainsString("\x1f", $output);
        $this->assertStringContainsString('Ripple Test', $output);
        $this->assertStringContainsString("src/Example.php", $output);
    }
}
