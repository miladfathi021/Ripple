<?php

declare(strict_types=1);

namespace Ripple\Git\History;

use DateTimeImmutable;
use Exception;

final class GitHistoryLogParser
{
    /**
     * @return list<HistoryCommit>
     */
    public function parse(string $output): array
    {
        if (trim($output) === '') {
            return [];
        }

        $records = preg_split("/\x1e/", $output, -1, PREG_SPLIT_NO_EMPTY);
        if ($records === false) {
            throw new GitHistoryAnalysisFailed('Malformed Git history output.');
        }

        $commits = [];
        foreach ($records as $record) {
            $commits[] = $this->parseRecord(ltrim($record, "\r\n"));
        }

        return $commits;
    }

    private function parseRecord(string $record): HistoryCommit
    {
        $lines = preg_split("/\r?\n/", $record);
        if ($lines === false || $lines === []) {
            throw new GitHistoryAnalysisFailed('Malformed Git history output.');
        }

        $header = array_shift($lines);
        $parts = explode("\x1f", $header);
        if (count($parts) !== 4) {
            throw new GitHistoryAnalysisFailed('Malformed Git history output.');
        }

        [$hash, $name, $email, $timestamp] = $parts;
        if ($hash === '' || $name === '' || $timestamp === '') {
            throw new GitHistoryAnalysisFailed('Malformed Git history output.');
        }

        if (preg_match('/^[0-9a-f]{7,64}$/i', $hash) !== 1) {
            throw new GitHistoryAnalysisFailed('Malformed Git history output.');
        }

        $committedAt = $this->parseTimestamp($timestamp);
        $added = 0;
        $deleted = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+|-)\t(\d+|-)\t(.*)$/', $line, $match) !== 1) {
                throw new GitHistoryAnalysisFailed('Malformed Git history output.');
            }

            $added += $match[1] === '-' ? 0 : (int) $match[1];
            $deleted += $match[2] === '-' ? 0 : (int) $match[2];
        }

        $author = $email === '' ? $name : $name . ' <' . $email . '>';

        return new HistoryCommit($hash, $author, $committedAt, $added, $deleted);
    }

    private function parseTimestamp(string $timestamp): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $timestamp);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (Exception) {
            throw new GitHistoryAnalysisFailed(
                'Git did not provide a valid commit timestamp: ' . $timestamp,
            );
        }
    }
}
