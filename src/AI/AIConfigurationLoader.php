<?php

declare(strict_types=1);

namespace Ripple\AI;

use JsonException;
use stdClass;
use Throwable;

final class AIConfigurationLoader
{
    public const FILENAME = 'ripple.json';

    public function load(string $path): AIConfiguration
    {
        if (!is_file($path)) {
            return AIConfiguration::disabled();
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw $this->failure($path, 'The configuration file could not be read.');
        }

        try {
            $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->failure($path, 'Invalid JSON: ' . $exception->getMessage(), $exception);
        }

        if (!$decoded instanceof stdClass) {
            throw $this->failure($path, 'Configuration must be a JSON object.');
        }

        if (!property_exists($decoded, 'ai')) {
            return AIConfiguration::disabled();
        }

        if (!$decoded->ai instanceof stdClass) {
            throw $this->failure($path, '"ai" must be an object.');
        }

        if (!property_exists($decoded->ai, 'enabled')) {
            return AIConfiguration::disabled();
        }

        if (!is_bool($decoded->ai->enabled)) {
            throw $this->failure($path, '"ai.enabled" must be a boolean.');
        }

        return new AIConfiguration($decoded->ai->enabled);
    }

    public static function pathForWorkingDirectory(string $workingDirectory): string
    {
        if ($workingDirectory === '' || $workingDirectory === '.') {
            return self::FILENAME;
        }

        return rtrim($workingDirectory, '/\\') . '/' . self::FILENAME;
    }

    private function failure(string $path, string $reason, ?Throwable $previous = null): AIProviderException
    {
        return new AIProviderException(
            "Ripple could not load AI configuration:\n{$path}\n\nReason:\n{$reason}",
            0,
            $previous,
        );
    }
}
