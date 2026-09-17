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

        if ($decoded->ai->enabled !== true) {
            return AIConfiguration::disabled();
        }

        return new AIConfiguration(
            enabled: true,
            provider: $this->requiredString($decoded->ai, 'provider', $path),
            model: $this->optionalString($decoded->ai, 'model', $path),
            timeoutSeconds: $this->timeoutSeconds($decoded->ai, $path),
            baseUrl: $this->optionalString($decoded->ai, 'base_url', $path),
        );
    }

    public static function pathForWorkingDirectory(string $workingDirectory): string
    {
        if ($workingDirectory === '' || $workingDirectory === '.') {
            return self::FILENAME;
        }

        return rtrim($workingDirectory, '/\\') . '/' . self::FILENAME;
    }

    private function requiredString(stdClass $ai, string $key, string $path): string
    {
        if (!property_exists($ai, $key)) {
            throw $this->failure($path, 'AI is enabled but no provider is configured.');
        }

        $value = $this->optionalString($ai, $key, $path);
        if ($value === null) {
            throw $this->failure($path, '"ai.' . $key . '" must be a non-empty string.');
        }

        return $value;
    }

    private function optionalString(stdClass $ai, string $key, string $path): ?string
    {
        if (!property_exists($ai, $key)) {
            return null;
        }

        $value = $ai->{$key};
        if (!is_string($value) || trim($value) === '' || trim($value) !== $value) {
            throw $this->failure($path, '"ai.' . $key . '" must be a non-empty string.');
        }

        return $value;
    }

    private function timeoutSeconds(stdClass $ai, string $path): int
    {
        if (!property_exists($ai, 'timeout_seconds')) {
            return AIConfiguration::DEFAULT_TIMEOUT_SECONDS;
        }

        $value = $ai->timeout_seconds;
        if (!is_int($value) || $value < 1) {
            throw $this->failure($path, '"ai.timeout_seconds" must be a positive integer.');
        }

        return $value;
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
