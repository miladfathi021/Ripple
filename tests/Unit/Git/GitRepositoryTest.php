<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

    public function testGitInvocationsAreNonInteractiveAndNotShellInterpolated(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Git/GitRepository.php');

        $this->assertStringContainsString("array_merge(['git', '-C', \$this->workingDirectory], \$arguments)", $source);
        $this->assertStringContainsString("unset(\$environment['RIPPLE_AI_API_KEY'], \$environment['Authorization'])", $source);
        $this->assertStringContainsString("\$environment['GIT_TERMINAL_PROMPT'] = '0'", $source);
        $this->assertStringContainsString("\$environment['GIT_OPTIONAL_LOCKS'] = '0'", $source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('passthru', $source);
        $this->assertDoesNotMatchRegularExpression('/proc_open\(\s*[\'"]git /', $source);
    }

    public function testGitChildProcessDoesNotInheritTheOpenAiApiKey(): void
    {
        $previousKey = getenv('RIPPLE_AI_API_KEY');
        $previousAuthorization = getenv('Authorization');
        putenv('RIPPLE_AI_API_KEY=sk-test-secret-key-do-not-leak');
        putenv('Authorization=Bearer sk-test-secret-key-do-not-leak');

        try {
            $this->assertSame('sk-test-secret-key-do-not-leak', getenv('RIPPLE_AI_API_KEY'));
            $this->assertSame('Bearer sk-test-secret-key-do-not-leak', getenv('Authorization'));

            $method = new ReflectionMethod(GitRepository::class, 'processEnvironment');
            $environment = $method->invoke(new GitRepository('.'));

            $this->assertIsArray($environment);
            $this->assertArrayNotHasKey('RIPPLE_AI_API_KEY', $environment);
            $this->assertArrayNotHasKey('Authorization', $environment);
            $this->assertSame('0', $environment['GIT_TERMINAL_PROMPT']);
            $this->assertSame('0', $environment['GIT_OPTIONAL_LOCKS']);
            $this->assertSame('sk-test-secret-key-do-not-leak', getenv('RIPPLE_AI_API_KEY'));
        } finally {
            if (is_string($previousKey)) {
                putenv('RIPPLE_AI_API_KEY=' . $previousKey);
            } else {
                putenv('RIPPLE_AI_API_KEY');
            }
            if (is_string($previousAuthorization)) {
                putenv('Authorization=' . $previousAuthorization);
            } else {
                putenv('Authorization');
            }
        }
    }
}
