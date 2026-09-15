<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git\History;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\GitRepository;
use Ripple\Git\History\FileChurn;
use Ripple\Git\History\GitHistoryAnalysisFailed;
use Ripple\Git\History\GitHistoryAnalyzer;
use Ripple\Git\History\GitHistoryLogParser;
use Ripple\Tests\Support\TemporaryGitRepository;

final class GitHistoryAnalyzerTest extends TestCase
{
    public function testSumsCommitAndLineCountsAcrossHistory(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Service.php', $this->lines(10), 'one');
        $repository->commitFile('src/Service.php', $this->lines(9) . $this->lines(5, 'added'), 'two');
        $repository->commitFile(
            'src/Service.php',
            $this->lines(7) . $this->lines(20, 'later'),
            'three',
        );

        $churn = $this->analyze($repository, 'src/Service.php');

        $this->assertSame('src/Service.php', $churn->file);
        $this->assertSame(3, $churn->commitCount);
        $this->assertSame(35, $churn->linesAdded);
        $this->assertSame(8, $churn->linesDeleted);
        $this->assertSame(1, $churn->contributorsCount);
        $this->assertInstanceOf(DateTimeImmutable::class, $churn->lastChangedAt);
    }

    public function testCountsDistinctGitAuthorIdentities(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile(
            'src/Service.php',
            $this->lines(1),
            'one',
            'Alice',
            'alice@example.com',
        );
        $repository->commitFile(
            'src/Service.php',
            $this->lines(2),
            'two',
            'Alice',
            'alice@example.com',
        );
        $repository->commitFile(
            'src/Service.php',
            $this->lines(3),
            'three',
            'Bob',
            'bob@example.com',
        );

        $churn = $this->analyze($repository, 'src/Service.php');

        $this->assertSame(3, $churn->commitCount);
        $this->assertSame(2, $churn->contributorsCount);
    }

    public function testSelectsTheNewestCommitTimestamp(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile(
            'src/Service.php',
            $this->lines(1),
            'old',
            date: '2026-01-01T00:00:00+00:00',
        );
        $repository->commitFile(
            'src/Service.php',
            $this->lines(2),
            'newest',
            date: '2026-09-14T13:42:10+00:00',
        );
        $repository->commitFile(
            'src/Service.php',
            $this->lines(3),
            'middle',
            date: '2026-03-01T00:00:00+00:00',
        );

        $churn = $this->analyze($repository, 'src/Service.php');

        $this->assertNotNull($churn->lastChangedAt);
        $this->assertSame('2026-09-14T13:42:10+00:00', $churn->lastChangedAt->format(DATE_ATOM));
    }

    public function testNewlyAddedFilesHaveNoHistoricalChurn(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Existing.php', $this->lines(1), 'seed');

        $result = $this->analyzer($repository)->analyze([
            new ChangedFile('src/BrandNew.php', ChangeType::Added, range(1, 10), []),
        ]);
        $churn = $result->get('src/BrandNew.php');

        $this->assertNotNull($churn);
        $this->assertSame(0, $churn->commitCount);
        $this->assertSame(0, $churn->linesAdded);
        $this->assertSame(0, $churn->linesDeleted);
        $this->assertSame(0, $churn->contributorsCount);
        $this->assertNull($churn->lastChangedAt);
    }

    public function testBinaryNumstatDoesNotFailAnalysis(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('assets/logo.bin', "\x89PNG\r\n\x1a\n" . str_repeat("\x00\xff", 64), 'binary');

        $churn = $this->analyze($repository, 'assets/logo.bin');

        $this->assertSame(1, $churn->commitCount);
        $this->assertSame(0, $churn->linesAdded);
        $this->assertSame(0, $churn->linesDeleted);
        $this->assertSame(1, $churn->contributorsCount);
        $this->assertNotNull($churn->lastChangedAt);
    }

    public function testReportsTheCurrentPathAndFollowsRenameHistory(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/old.php', $this->lines(3), 'create');
        $repository->git(['mv', '--', 'src/old.php', 'src/new.php']);
        $repository->git(['commit', '-qm', 'rename']);
        $repository->commitFile('src/new.php', $this->lines(4), 'edit after rename');

        $result = $this->analyzer($repository)->analyze([
            new ChangedFile('src/new.php', ChangeType::Renamed, [4], [], 'src/old.php'),
        ]);
        $churn = $result->get('src/new.php');

        $this->assertNotNull($churn);
        $this->assertSame('src/new.php', $churn->file);
        $this->assertSame(3, $churn->commitCount);
        $this->assertGreaterThan(0, $churn->linesAdded);
        $this->assertNull($result->get('src/old.php'));
    }

    public function testFallsBackToThePreviousPathWhenCurrentPathHasNoHistory(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/old.php', $this->lines(2), 'create');

        $result = $this->analyzer($repository)->analyze([
            new ChangedFile('src/new.php', ChangeType::Renamed, [1], [], 'src/old.php'),
        ]);
        $churn = $result->get('src/new.php');

        $this->assertNotNull($churn);
        $this->assertSame('src/new.php', $churn->file);
        $this->assertSame(1, $churn->commitCount);
        $this->assertSame(2, $churn->linesAdded);
    }

    public function testDeletedFilesUseGitHistoryForTheDeletedPath(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/gone.php', $this->lines(4), 'create');
        $repository->delete('src/gone.php');

        $result = $this->analyzer($repository)->analyze([
            new ChangedFile('src/gone.php', ChangeType::Deleted, [], [1, 2, 3, 4]),
        ]);
        $churn = $result->get('src/gone.php');

        $this->assertNotNull($churn);
        $this->assertSame(1, $churn->commitCount);
        $this->assertSame(4, $churn->linesAdded);
        $this->assertSame(0, $churn->linesDeleted);
        $this->assertNotNull($churn->lastChangedAt);
    }

    public function testIncludesNonPhpChangedFiles(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('resources/js/app.js', "console.log(1);\n", 'js');
        $repository->commitFile('resources/js/Widget.vue', "<template></template>\n", 'vue');
        $repository->commitFile('composer.json', "{}\n", 'json');

        $result = $this->analyzer($repository)->analyze([
            new ChangedFile('resources/js/app.js', ChangeType::Modified, [1], []),
            new ChangedFile('resources/js/Widget.vue', ChangeType::Modified, [1], []),
            new ChangedFile('composer.json', ChangeType::Modified, [1], []),
        ]);

        $this->assertSame(1, $result->get('resources/js/app.js')?->commitCount);
        $this->assertSame(1, $result->get('resources/js/Widget.vue')?->commitCount);
        $this->assertSame(1, $result->get('composer.json')?->commitCount);
    }

    public function testCountsEveryCommitGitReturnsIncludingMerges(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Service.php', $this->lines(1), 'main');
        $repository->git(['checkout', '-q', '-b', 'feature']);
        $repository->commitFile('src/Service.php', $this->lines(2), 'feature');
        $repository->git(['checkout', '-q', 'main']);
        $repository->git(['merge', '--no-ff', '-q', '-m', 'merge feature', 'feature']);

        $first = $this->analyze($repository, 'src/Service.php');
        $second = $this->analyze($repository, 'src/Service.php');

        $this->assertSame($first->commitCount, $second->commitCount);
        $this->assertGreaterThanOrEqual(2, $first->commitCount);
    }

    public function testGitCommandFailureRaisesADomainException(): void
    {
        $directory = TemporaryGitRepository::emptyDirectory();
        $analyzer = new GitHistoryAnalyzer(new GitRepository($directory));

        $this->expectException(GitHistoryAnalysisFailed::class);
        $this->expectExceptionMessage('Ripple could not analyze Git history:');
        $this->expectExceptionMessage('not a Git repository.');

        $analyzer->analyze([
            new ChangedFile('src/Service.php', ChangeType::Modified, [1], []),
        ]);
    }

    public function testMalformedGitOutputRaisesADomainException(): void
    {
        $this->expectException(GitHistoryAnalysisFailed::class);
        $this->expectExceptionMessage('Malformed Git history output.');

        (new GitHistoryLogParser())->parse(
            "\x1enot-a-hash\x1fAlice\x1fa@x.com\x1f2026-09-14T13:42:10+00:00\n",
        );
    }

    public function testEmptyChangedFileListProducesAnEmptyResult(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Service.php', $this->lines(1), 'seed');

        $result = $this->analyzer($repository)->analyze([]);

        $this->assertTrue($result->isEmpty());
    }

    private function analyze(TemporaryGitRepository $repository, string $path): FileChurn
    {
        $result = $this->analyzer($repository)->analyze([
            new ChangedFile($path, ChangeType::Modified, [1], []),
        ]);
        $churn = $result->get($path);
        $this->assertNotNull($churn);

        return $churn;
    }

    private function analyzer(TemporaryGitRepository $repository): GitHistoryAnalyzer
    {
        return new GitHistoryAnalyzer(new GitRepository($repository->path));
    }

    private function lines(int $count, string $prefix = 'line'): string
    {
        $lines = [];
        for ($i = 1; $i <= $count; $i++) {
            $lines[] = $prefix . $i;
        }

        return implode("\n", $lines) . "\n";
    }
}
