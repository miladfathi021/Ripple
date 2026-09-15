<?php

declare(strict_types=1);

namespace Ripple\Analysis\Index;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RepositoryPhpFileScanner
{
    /** @var list<string> */
    private const EXCLUDED_DIRECTORY_NAMES = [
        '.git',
        'vendor',
        'node_modules',
        'storage',
    ];

    /**
     * @return list<string> Deterministic relative paths from the repository root.
     */
    public function scan(string $rootDirectory): array
    {
        $root = realpath($rootDirectory);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $directory = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::UNIX_PATHS,
        );

        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function (SplFileInfo $current) use ($root): bool {
                if ($current->isLink()) {
                    return false;
                }

                if ($current->isDir()) {
                    return !$this->isExcludedDirectory($this->relativePath($root, $current->getPathname()));
                }

                return true;
            },
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!$file instanceof SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            if (!str_ends_with(strtolower($file->getFilename()), '.php')) {
                continue;
            }

            $files[] = $this->relativePath($root, $file->getPathname());
        }

        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    private function isExcludedDirectory(string $relative): bool
    {
        if ($relative === 'bootstrap/cache' || str_starts_with($relative, 'bootstrap/cache/')) {
            return true;
        }

        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, self::EXCLUDED_DIRECTORY_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $root, string $absolute): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalized = str_replace('\\', '/', $absolute);

        if (str_starts_with($normalized, $normalizedRoot . '/')) {
            return substr($normalized, strlen($normalizedRoot) + 1);
        }

        return ltrim($normalized, '/');
    }
}
