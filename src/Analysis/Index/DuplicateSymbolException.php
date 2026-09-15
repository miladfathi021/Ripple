<?php

declare(strict_types=1);

namespace Ripple\Analysis\Index;

use RuntimeException;

final class DuplicateSymbolException extends RuntimeException
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        public readonly string $symbolId,
        public readonly array $files,
    ) {
        $paths = $files;
        sort($paths, SORT_STRING);
        $list = implode("\n", array_map(
            static fn (string $file): string => '- ' . $file,
            $paths,
        ));

        parent::__construct(
            "Duplicate symbol detected:\n{$symbolId}\n\nDefinitions found in:\n{$list}",
        );
    }
}
