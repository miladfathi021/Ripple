<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Git\GitRepository;
use Ripple\Reporting\ReportFormatterFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeCommandTest extends TestCase
{
    public function testTextOutputSummarizesATrackedChange(): void
    {
        $tester = $this->commandTester($this->repositoryWithChange());
        $statusCode = $tester->execute(['--format' => 'text']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('🌊 Ripple', $tester->getDisplay());
        $this->assertStringContainsString('Changed files: 1', $tester->getDisplay());
        $this->assertStringContainsString('M src/Example.php', $tester->getDisplay());
        $this->assertStringContainsString('+1', $tester->getDisplay());
        $this->assertStringContainsString('-1', $tester->getDisplay());
        $this->assertStringContainsString('Changed symbols:', $tester->getDisplay());
        $this->assertStringContainsString('Example::run()', $tester->getDisplay());
        $this->assertStringContainsString('Direct impact:', $tester->getDisplay());
        $this->assertStringContainsString('Blast radius:', $tester->getDisplay());
        $this->assertStringContainsString('None', $tester->getDisplay());
        $this->assertStringContainsString('Test impact:', $tester->getDisplay());
        $this->assertStringContainsString('Dependency graph:', $tester->getDisplay());
        $this->assertStringContainsString('Reverse dependencies:', $tester->getDisplay());
        $this->assertStringContainsString('Repository index:', $tester->getDisplay());
        $this->assertStringContainsString('PHP files: 1', $tester->getDisplay());
        $this->assertStringNotContainsString('"status"', $tester->getDisplay());
        $this->assertStringNotContainsString('Risk factors:', $tester->getDisplay());
        $this->assertStringContainsString('Risk score:', $tester->getDisplay());
        $this->assertStringContainsString('0/100 — Low', $tester->getDisplay());
        $this->assertStringNotContainsString('Affected flows:', $tester->getDisplay());
        $this->assertStringNotContainsString('Semantic impact:', $tester->getDisplay());
        $this->assertStringContainsString('Git history:', $tester->getDisplay());
        $this->assertStringContainsString('src/Example.php', $tester->getDisplay());
        $this->assertStringContainsString('commits: 1', $tester->getDisplay());
    }

    public function testJsonOutputExposesDiffData(): void
    {
        $tester = $this->commandTester($this->repositoryWithChange());
        $statusCode = $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $statusCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('src/Example.php', $payload['diff']['files'][0]['path']);
        $this->assertSame('modified', $payload['diff']['files'][0]['change_type']);
        $this->assertSame([7], $payload['diff']['files'][0]['added_lines']);
        $this->assertSame([7], $payload['diff']['files'][0]['deleted_lines']);
        $this->assertSame('Example::run', $payload['changed_symbols'][0]['fully_qualified_name']);
        $this->assertSame([], $payload['direct_impact']);
        $this->assertSame([], $payload['blast_radius']);
        $this->assertSame([], $payload['test_impact']);
        $this->assertSame([], $payload['risk_factors']);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('low', $payload['risk_score']['level']);
        $this->assertSame([], $payload['risk_score']['contributions']);
        $this->assertSame([], $payload['affected_flows']);
        $this->assertSame(
            [
                'blast_radius' => [],
                'affected_flows' => [],
            ],
            $payload['semantic_impact'],
        );
        $this->assertCount(1, $payload['churn']);
        $this->assertSame('src/Example.php', $payload['churn'][0]['file']);
        $this->assertSame(1, $payload['churn'][0]['commit_count']);
        $this->assertSame(1, $payload['churn'][0]['contributors_count']);
        $this->assertNotNull($payload['churn'][0]['last_changed_at']);
        $this->assertSame([7], $payload['changed_symbols'][0]['changed_lines']);
        $this->assertSame('src/Example.php', $payload['unmapped_deleted_lines'][0]['file']);
        $this->assertSame([7], $payload['unmapped_deleted_lines'][0]['lines']);
        $this->assertSame(1, $payload['project_index']['php_files']);
        $this->assertSame(2, $payload['project_index']['symbols']);
        $this->assertSame(0, $payload['project_index']['dependencies']);
        $this->assertCount(2, $payload['graph']['nodes']);
        $this->assertSame([], $payload['graph']['edges']);
        $this->assertSame(['dependents' => []], $payload['reverse_graph']);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
    }

    public function testFailsOutsideAGitRepository(): void
    {
        $tester = $this->commandTester(TemporaryGitRepository::emptyDirectory());
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString(
            'Ripple could not analyze this directory:',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString('not a Git repository.', $tester->getDisplay());
        $this->assertStringNotContainsString('fatal:', $tester->getDisplay());
    }

    public function testAnalysisFailureRemainsAFailureWhenACommentFileIsRequested(): void
    {
        $commentFile = sys_get_temp_dir() . '/ripple-comment-' . bin2hex(random_bytes(4)) . '.md';
        $tester = $this->commandTester(TemporaryGitRepository::emptyDirectory());
        $statusCode = $tester->execute([
            '--format' => 'json',
            '--comment-file' => $commentFile,
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('ok', $payload['status']);
        $this->assertFileExists($commentFile);
        $this->assertStringStartsWith("<!-- ripple-analysis -->\n", (string) file_get_contents($commentFile));
        @unlink($commentFile);
    }

    public function testWritesACompactCommentFileAlongsideJson(): void
    {
        $commentFile = sys_get_temp_dir() . '/ripple-comment-' . bin2hex(random_bytes(4)) . '.md';
        $tester = $this->commandTester($this->repositoryWithChange());
        $statusCode = $tester->execute([
            '--format' => 'json',
            '--comment-file' => $commentFile,
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $payload['status']);
        $this->assertFileExists($commentFile);
        $comment = (string) file_get_contents($commentFile);
        $this->assertStringContainsString('🌊 Ripple — Code Impact Analysis', $comment);
        $this->assertStringContainsString('<!-- ripple-analysis -->', $comment);
        $this->assertStringContainsString('Example::run()', $comment);
        @unlink($commentFile);
    }

    public function testCommentFileWriteFailureDoesNotCorruptJsonOutput(): void
    {
        $blocked = sys_get_temp_dir() . '/ripple-comment-dir-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($blocked));
        $tester = $this->commandTester($this->repositoryWithChange());
        $statusCode = $tester->execute(
            [
                '--format' => 'json',
                '--comment-file' => $blocked,
            ],
            ['capture_stderr' => true],
        );

        $this->assertSame(Command::SUCCESS, $statusCode);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('risk_score', $payload);
        $this->assertStringNotContainsString('Ripple could not write the comment file.', $tester->getDisplay());
        @rmdir($blocked);
    }

    private function repositoryWithChange(): string
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', $this->phpClass(1), 'initial');
        $repository->write('src/Example.php', $this->phpClass(2));

        return $repository->path;
    }

    private function commandTester(string $workingDirectory): CommandTester
    {
        $command = new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
        );

        return new CommandTester($command);
    }

    private function phpClass(int $value): string
    {
        return <<<PHP
<?php

class Example
{
    public function run(): void
    {
        \$value = {$value};
    }
}

PHP;
    }
}
