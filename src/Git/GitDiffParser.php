<?php

declare(strict_types=1);

namespace Ripple\Git;

final class GitDiffParser
{
    public function parse(string $diff): DiffResult
    {
        if (trim($diff) === '') {
            return new DiffResult([]);
        }

        $files = [];
        $current = null;
        $oldLine = 0;
        $newLine = 0;
        $inHunk = false;

        foreach ($this->lines($diff) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                if ($current !== null) {
                    $files[] = $this->toChangedFile($current);
                }

                $current = $this->startFile($line);
                $inHunk = false;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (str_starts_with($line, 'new file mode ')) {
                $current['changeType'] = ChangeType::Added;
                continue;
            }

            if (str_starts_with($line, 'deleted file mode ')) {
                $current['changeType'] = ChangeType::Deleted;
                continue;
            }

            if (str_starts_with($line, 'rename from ')) {
                $current['changeType'] = ChangeType::Renamed;
                $current['oldPath'] = $this->unquote(substr($line, strlen('rename from ')));
                continue;
            }

            if (str_starts_with($line, 'rename to ')) {
                $current['changeType'] = ChangeType::Renamed;
                $current['path'] = $this->unquote(substr($line, strlen('rename to ')));
                continue;
            }

            if ($this->isIgnorableHeader($line)) {
                continue;
            }

            if (str_starts_with($line, '--- ')) {
                $path = $this->parseHeaderPath($line);
                if ($path === null) {
                    $current['oldMissing'] = true;
                } elseif ($current['changeType'] !== ChangeType::Renamed) {
                    $current['oldPath'] = $path;
                }
                continue;
            }

            if (str_starts_with($line, '+++ ')) {
                $path = $this->parseHeaderPath($line);
                if ($path === null) {
                    $current['newMissing'] = true;
                } else {
                    $current['path'] = $path;
                }
                continue;
            }

            if (str_starts_with($line, '@@ ')) {
                [$oldLine, $newLine] = $this->parseHunkHeader($line);
                $inHunk = true;
                continue;
            }

            if (str_starts_with($line, '\\')) {
                continue;
            }

            if (!$inHunk) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $current['addedLines'][] = $newLine;
                $newLine++;
                continue;
            }

            if (str_starts_with($line, '-')) {
                $current['deletedLines'][] = $oldLine;
                $oldLine++;
                continue;
            }

            $oldLine++;
            $newLine++;
        }

        if ($current !== null) {
            $files[] = $this->toChangedFile($current);
        }

        return new DiffResult($files);
    }

    /**
     * @return list<string>
     */
    private function lines(string $diff): array
    {
        return preg_split("/\r\n|\n|\r/", $diff) ?: [];
    }

    /**
     * @return array{
     *     path: string,
     *     oldPath: ?string,
     *     changeType: ChangeType,
     *     addedLines: list<int>,
     *     deletedLines: list<int>,
     *     oldMissing: bool,
     *     newMissing: bool
     * }
     */
    private function startFile(string $line): array
    {
        [$oldPath, $newPath] = $this->parseGitPaths(substr($line, strlen('diff --git ')));

        return [
            'path' => $newPath,
            'oldPath' => $oldPath !== $newPath ? $oldPath : null,
            'changeType' => ChangeType::Modified,
            'addedLines' => [],
            'deletedLines' => [],
            'oldMissing' => false,
            'newMissing' => false,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseGitPaths(string $rest): array
    {
        if (preg_match('/^"([^"]+)" "([^"]+)"$/', $rest, $matches) === 1) {
            return [
                $this->stripPrefix($this->unquote('"' . $matches[1] . '"')),
                $this->stripPrefix($this->unquote('"' . $matches[2] . '"')),
            ];
        }

        $separator = strpos($rest, ' b/');
        if ($separator === false) {
            $path = $this->stripPrefix($this->unquote($rest));

            return [$path, $path];
        }

        return [
            $this->stripPrefix($this->unquote(substr($rest, 0, $separator))),
            $this->stripPrefix($this->unquote(substr($rest, $separator + 1))),
        ];
    }

    private function parseHeaderPath(string $line): ?string
    {
        $payload = substr($line, 4);
        $payload = explode("\t", $payload, 2)[0];
        $payload = $this->unquote($payload);

        if ($payload === '/dev/null') {
            return null;
        }

        return $this->stripPrefix($payload);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseHunkHeader(string $line): array
    {
        if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $matches) !== 1) {
            return [0, 0];
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function isIgnorableHeader(string $line): bool
    {
        foreach (
            [
                'index ',
                'similarity index ',
                'dissimilarity index ',
                'old mode ',
                'new mode ',
                'copy from ',
                'copy to ',
                'Binary files ',
            ] as $prefix
        ) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function stripPrefix(string $path): string
    {
        if (str_starts_with($path, 'a/') || str_starts_with($path, 'b/')) {
            return substr($path, 2);
        }

        return $path;
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * @param array{
     *     path: string,
     *     oldPath: ?string,
     *     changeType: ChangeType,
     *     addedLines: list<int>,
     *     deletedLines: list<int>,
     *     oldMissing: bool,
     *     newMissing: bool
     * } $current
     */
    private function toChangedFile(array $current): ChangedFile
    {
        $changeType = $current['changeType'];

        if ($changeType === ChangeType::Modified) {
            if ($current['oldMissing'] && !$current['newMissing']) {
                $changeType = ChangeType::Added;
            } elseif ($current['newMissing'] && !$current['oldMissing']) {
                $changeType = ChangeType::Deleted;
            }
        }

        return new ChangedFile(
            path: $current['path'],
            changeType: $changeType,
            addedLines: $current['addedLines'],
            deletedLines: $current['deletedLines'],
            oldPath: $current['oldPath'],
        );
    }
}
