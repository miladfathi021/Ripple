<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

use JsonException;
use stdClass;
use Throwable;

final class RippleConfigurationLoader
{
    public function load(string $path): RippleConfiguration
    {
        if (!is_file($path)) {
            return RippleConfiguration::empty();
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw $this->failure($path, 'The semantic configuration file could not be read.');
        }

        try {
            $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->failure($path, 'Invalid JSON: ' . $exception->getMessage(), $exception);
        }

        if (!$decoded instanceof stdClass) {
            throw $this->failure($path, 'Semantic configuration must be a JSON object.');
        }

        $framework = $this->framework($decoded, $path);
        $hasAnnotations = property_exists($decoded, 'semantic_annotations');
        $hasAi = property_exists($decoded, 'ai');
        if (!$hasAnnotations && $framework === null && !$hasAi) {
            throw $this->failure($path, 'Missing required key "semantic_annotations".');
        }

        $annotations = [];
        if ($hasAnnotations) {
            if (!is_array($decoded->semantic_annotations)) {
                throw $this->failure($path, '"semantic_annotations" must be an array.');
            }

            $unique = [];
            foreach (array_values($decoded->semantic_annotations) as $index => $item) {
                $annotation = $this->annotationFromItem($path, $item, $index);
                $key = $annotation->symbolId . "\n" . $annotation->type->value;
                if (!isset($unique[$key])) {
                    $unique[$key] = $annotation;
                }
            }

            $annotations = array_values($unique);
            usort(
                $annotations,
                static function (SemanticAnnotation $left, SemanticAnnotation $right): int {
                    return [$left->symbolId, $left->type->value] <=> [$right->symbolId, $right->type->value];
                },
            );
        }

        return new RippleConfiguration($framework, $annotations);
    }

    private function framework(stdClass $decoded, string $path): ?string
    {
        if (!property_exists($decoded, 'framework')) {
            return null;
        }

        if (!is_string($decoded->framework) || $decoded->framework === '' || trim($decoded->framework) !== $decoded->framework) {
            throw $this->failure($path, '"framework" must be a non-empty string.');
        }

        return $decoded->framework;
    }

    private function annotationFromItem(string $path, mixed $item, int $index): SemanticAnnotation
    {
        $location = 'semantic_annotations[' . $index . ']';

        if (!$item instanceof stdClass) {
            throw $this->failure($path, $location . ' must be an object.');
        }

        if (!property_exists($item, 'symbol')) {
            throw $this->failure($path, $location . ' is missing required key "symbol".');
        }

        if (!property_exists($item, 'type')) {
            throw $this->failure($path, $location . ' is missing required key "type".');
        }

        if (!is_string($item->symbol) || $item->symbol === '' || trim($item->symbol) !== $item->symbol) {
            throw $this->failure($path, $location . '.symbol must be a non-empty string.');
        }

        if (!is_string($item->type) || $item->type === '') {
            throw $this->failure($path, $location . '.type must be a non-empty string.');
        }

        try {
            return new SemanticAnnotation($item->symbol, FlowSemanticType::fromConfig($item->type));
        } catch (InvalidSemanticConfigurationException $exception) {
            throw $this->failure($path, $location . ': ' . $exception->getMessage(), $exception);
        }
    }

    private function failure(string $path, string $reason, ?Throwable $previous = null): InvalidSemanticConfigurationException
    {
        return new InvalidSemanticConfigurationException(
            "Ripple could not load semantic configuration:\n{$path}\n\nReason:\n{$reason}",
            0,
            $previous,
        );
    }
}
