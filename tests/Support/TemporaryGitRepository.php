<?php

declare(strict_types=1);

namespace Ripple\Tests\Support;

use RuntimeException;

final class TemporaryGitRepository
{
    private function __construct(
        public readonly string $path,
    ) {
    }

    public static function create(): self
    {
        $root = sys_get_temp_dir() . '/ripple-' . bin2hex(random_bytes(8));
        $template = $root . '/template';
        $path = $root . '/repo';
        mkdir($template, 0777, true);
        mkdir($path, 0777, true);

        self::run($path, ['init', '-q', '-b', 'main', '--template=' . $template]);
        self::run($path, ['config', 'user.name', 'Ripple Test']);
        self::run($path, ['config', 'user.email', 'ripple@example.com']);
        self::run($path, ['config', 'commit.gpgsign', 'false']);

        return new self($path);
    }

    public static function emptyDirectory(): string
    {
        $path = sys_get_temp_dir() . '/ripple-empty-' . bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return $path;
    }

    public function commitFile(
        string $relativePath,
        string $contents,
        string $message = 'commit',
        ?string $authorName = null,
        ?string $authorEmail = null,
        ?string $date = null,
    ): void {
        $fullPath = $this->path . '/' . $relativePath;
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($fullPath, $contents);
        $this->git(['add', '--', $relativePath]);
        $commit = ['commit', '-qm', $message];
        if ($authorName !== null && $authorEmail !== null) {
            $commit[] = '--author=' . $authorName . ' <' . $authorEmail . '>';
        }

        $environment = [];
        if ($date !== null) {
            $environment['GIT_AUTHOR_DATE'] = $date;
            $environment['GIT_COMMITTER_DATE'] = $date;
        }

        $this->git($commit, $environment);
    }

    public function write(string $relativePath, string $contents): void
    {
        file_put_contents($this->path . '/' . $relativePath, $contents);
    }

    public function delete(string $relativePath): void
    {
        unlink($this->path . '/' . $relativePath);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    public function git(array $arguments, array $environment = []): void
    {
        self::run($this->path, $arguments, $environment);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private static function run(string $path, array $arguments, array $environment = []): void
    {
        $command = array_merge(['git', '-C', $path], $arguments);
        $pipes = [];
        $env = null;
        if ($environment !== []) {
            $inherited = getenv();
            $env = array_merge(is_array($inherited) ? $inherited : [], $environment);
        }

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $path,
            $env,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Git could not be executed in the test repository.');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Git command failed in the test repository: ' . (is_string($stderr) ? trim($stderr) : ''),
            );
        }
    }
}
