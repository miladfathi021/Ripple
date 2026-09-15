<?php

declare(strict_types=1);

namespace Ripple\Analysis\ChangedSymbols;

final class PhpSourceReader
{
    public function __construct(
        private readonly string $rootDirectory = '.',
    ) {
    }

    public function read(string $relativePath): ?string
    {
        $path = $this->rootDirectory . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}
