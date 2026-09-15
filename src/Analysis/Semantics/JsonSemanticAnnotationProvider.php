<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

final class JsonSemanticAnnotationProvider implements SemanticAnnotationProvider
{
    public const FILENAME = 'ripple.json';

    public function __construct(
        private readonly string $path,
        private readonly RippleConfigurationLoader $loader = new RippleConfigurationLoader(),
    ) {
    }

    public static function forWorkingDirectory(string $workingDirectory): self
    {
        return new self(self::pathForWorkingDirectory($workingDirectory));
    }

    public static function pathForWorkingDirectory(string $workingDirectory): string
    {
        if ($workingDirectory === '' || $workingDirectory === '.') {
            return self::FILENAME;
        }

        return rtrim($workingDirectory, '/\\') . '/' . self::FILENAME;
    }

    /**
     * @return list<SemanticAnnotation>
     */
    public function getAnnotations(): array
    {
        return $this->loader->load($this->path)->semanticAnnotations;
    }
}
