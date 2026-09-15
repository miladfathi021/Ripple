<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk\Rules;

use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskFactorRule;
use Ripple\Analysis\Risk\RiskSeverity;

final class HistoricalChurnRule implements RiskFactorRule
{
    public const INFO_MIN_COMMITS = 10;
    public const WARNING_MIN_COMMITS = 25;
    public const HIGH_MIN_COMMITS = 50;

    public function evaluate(RiskFactorContext $context): array
    {
        $files = [];
        foreach ($context->churn->files as $churn) {
            $severity = $this->severityFor($churn->commitCount);
            if ($severity === null) {
                continue;
            }

            $files[] = [
                'file' => $churn->file,
                'commit_count' => $churn->commitCount,
                'severity' => $severity,
            ];
        }

        if ($files === []) {
            return [];
        }

        usort($files, $this->compareFiles(...));

        $highest = $files[0]['severity'];
        $maxCommits = $files[0]['commit_count'];
        $evidenceFiles = array_map(
            static fn (array $file): array => [
                'file' => $file['file'],
                'commit_count' => $file['commit_count'],
            ],
            $files,
        );

        return [
            new RiskFactor(
                code: RiskFactor::CODE_HISTORICAL_CHURN,
                severity: $highest,
                title: 'Historical churn',
                description: $this->description($files),
                value: $maxCommits,
                evidence: [
                    'max_commit_count' => $maxCommits,
                    'affected_files' => $evidenceFiles,
                ],
            ),
        ];
    }

    private function severityFor(int $commitCount): ?RiskSeverity
    {
        return match (true) {
            $commitCount >= self::HIGH_MIN_COMMITS => RiskSeverity::High,
            $commitCount >= self::WARNING_MIN_COMMITS => RiskSeverity::Warning,
            $commitCount >= self::INFO_MIN_COMMITS => RiskSeverity::Info,
            default => null,
        };
    }

    /**
     * @param list<array{file: string, commit_count: int, severity: RiskSeverity}> $files
     */
    private function description(array $files): string
    {
        if (count($files) === 1) {
            return sprintf(
                '%s has changed %d times in Git history.',
                basename($files[0]['file']),
                $files[0]['commit_count'],
            );
        }

        $lines = ['Frequently changed files:'];
        foreach ($files as $file) {
            $lines[] = sprintf('  %s — %d commits', $file['file'], $file['commit_count']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{file: string, commit_count: int, severity: RiskSeverity} $left
     * @param array{file: string, commit_count: int, severity: RiskSeverity} $right
     */
    private function compareFiles(array $left, array $right): int
    {
        return [
            -$left['severity']->rank(),
            -$left['commit_count'],
            $left['file'],
        ] <=> [
            -$right['severity']->rank(),
            -$right['commit_count'],
            $right['file'],
        ];
    }
}
