<?php

declare(strict_types=1);

namespace Ripple\Git;

final class GitRepository
{
    public function __construct(
        private readonly string $workingDirectory = '.',
    ) {
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function isInsideWorkTree(): bool
    {
        if (!is_dir($this->workingDirectory)) {
            return false;
        }

        $result = $this->execute(['rev-parse', '--is-inside-work-tree']);

        return $result['exitCode'] === 0 && trim($result['stdout']) === 'true';
    }

    public function workingTreeDiff(): string
    {
        if (!$this->isInsideWorkTree()) {
            if (is_dir($this->workingDirectory) && $this->gitIsUnavailable()) {
                throw new GitOperationFailed('Git could not be executed.');
            }

            throw new NotAGitRepository();
        }

        $result = $this->execute([
            'diff',
            '--no-color',
            '--no-ext-diff',
            '--find-renames',
        ]);

        if ($result['exitCode'] !== 0) {
            throw new GitOperationFailed('Git could not produce a working tree diff.');
        }

        return $result['stdout'];
    }

    public function fileHistory(string $path, bool $followRenames = false): string
    {
        if (!$this->isInsideWorkTree()) {
            if (is_dir($this->workingDirectory) && $this->gitIsUnavailable()) {
                throw new GitOperationFailed('Git could not be executed.');
            }

            throw new NotAGitRepository();
        }

        $result = $this->execute($this->historyArguments($path, $followRenames));
        if ($result['exitCode'] !== 0 && $followRenames) {
            $result = $this->execute($this->historyArguments($path, false));
        }

        if ($result['exitCode'] !== 0) {
            throw new GitOperationFailed('Git could not produce file history.');
        }

        return $result['stdout'];
    }

    /**
     * @return list<string>
     */
    private function historyArguments(string $path, bool $followRenames): array
    {
        $arguments = [
            'log',
            '--pretty=tformat:%x1e%H%x1f%an%x1f%ae%x1f%aI',
            '--numstat',
        ];
        if ($followRenames) {
            $arguments[] = '--follow';
        }
        $arguments[] = '--';
        $arguments[] = $path;

        return $arguments;
    }

    private function gitIsUnavailable(): bool
    {
        $result = $this->execute(['--version']);

        return $result['exitCode'] !== 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function execute(array $arguments): array
    {
        $command = array_merge(['git', '-C', $this->workingDirectory], $arguments);
        $pipes = [];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workingDirectory,
        );

        if (!is_resource($process)) {
            return [
                'exitCode' => 127,
                'stdout' => '',
                'stderr' => '',
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
