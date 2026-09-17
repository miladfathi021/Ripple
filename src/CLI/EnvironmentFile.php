<?php

declare(strict_types=1);

namespace Ripple\CLI;

final class EnvironmentFile
{
    public const FILENAME = '.env';

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return;
        }

        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        foreach (preg_split('/\R/', $contents) as $line) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$name, $value] = $parsed;
            if (getenv($name) !== false) {
                continue;
            }

            putenv($name . '=' . $value);
        }
    }

    public static function pathForWorkingDirectory(string $workingDirectory): string
    {
        if ($workingDirectory === '' || $workingDirectory === '.') {
            return self::FILENAME;
        }

        return rtrim($workingDirectory, '/\\') . '/' . self::FILENAME;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $separator = strpos($line, '=');
        if ($separator === false || $separator === 0) {
            return null;
        }

        $name = trim(substr($line, 0, $separator));
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return null;
        }

        $value = trim(substr($line, $separator + 1));
        $quote = $value === '' ? '' : $value[0];
        if (
            ($quote === '"' || $quote === "'")
            && strlen($value) >= 2
            && str_ends_with($value, $quote)
        ) {
            $value = substr($value, 1, -1);
        }

        return [$name, $value];
    }
}
