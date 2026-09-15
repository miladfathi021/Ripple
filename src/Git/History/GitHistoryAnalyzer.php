<?php

declare(strict_types=1);

namespace Ripple\Git\History;

use Ripple\Git\ChangedFile;
use Ripple\Git\GitOperationFailed;
use Ripple\Git\GitRepository;
use Ripple\Git\NotAGitRepository;

final class GitHistoryAnalyzer
{
    public function __construct(
        private readonly GitRepository $repository,
        private readonly GitHistoryLogParser $parser = new GitHistoryLogParser(),
    ) {
    }

    /**
     * @param list<ChangedFile> $changedFiles
     */
    public function analyze(array $changedFiles): ChurnResult
    {
        $files = [];
        foreach ($changedFiles as $file) {
            $files[] = $this->fileChurn($file);
        }

        return ChurnResult::fromFiles($files);
    }

    private function fileChurn(ChangedFile $file): FileChurn
    {
        $commits = $this->commitsFor($file->path);
        if ($commits === [] && $file->oldPath !== null && $file->oldPath !== $file->path) {
            $commits = $this->commitsFor($file->oldPath);
        }

        if ($commits === []) {
            return FileChurn::none($file->path);
        }

        $authors = [];
        $added = 0;
        $deleted = 0;
        $lastChangedAt = null;
        foreach ($commits as $commit) {
            $authors[$commit->author] = true;
            $added += $commit->linesAdded;
            $deleted += $commit->linesDeleted;
            if ($lastChangedAt === null || $commit->committedAt > $lastChangedAt) {
                $lastChangedAt = $commit->committedAt;
            }
        }

        return new FileChurn(
            $file->path,
            count($commits),
            $added,
            $deleted,
            count($authors),
            $lastChangedAt,
        );
    }

    /**
     * @return list<HistoryCommit>
     */
    private function commitsFor(string $path): array
    {
        try {
            $output = $this->repository->fileHistory($path, followRenames: true);
        } catch (NotAGitRepository $exception) {
            throw new GitHistoryAnalysisFailed(
                "Ripple could not analyze Git history:\n{$path}\n\nReason:\n" . $exception->getMessage(),
                0,
                $exception,
            );
        } catch (GitOperationFailed $exception) {
            throw new GitHistoryAnalysisFailed(
                "Ripple could not analyze Git history:\n{$path}\n\nReason:\n" . $exception->getMessage(),
                0,
                $exception,
            );
        }

        try {
            return $this->parser->parse($output);
        } catch (GitHistoryAnalysisFailed $exception) {
            throw new GitHistoryAnalysisFailed(
                "Ripple could not analyze Git history:\n{$path}\n\nReason:\n" . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }
}
