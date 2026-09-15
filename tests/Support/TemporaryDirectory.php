<?php

declare(strict_types=1);

namespace Ripple\Tests\Support;

final class TemporaryDirectory
{
    private function __construct(
        public readonly string $path,
    ) {
    }

    public static function create(): self
    {
        $path = sys_get_temp_dir() . '/ripple-fs-' . bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return new self($path);
    }

    public function write(string $relativePath, string $contents): void
    {
        $fullPath = $this->path . '/' . $relativePath;
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($fullPath, $contents);
    }

    public function mkdir(string $relativePath): void
    {
        $fullPath = $this->path . '/' . $relativePath;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }
    }
}
