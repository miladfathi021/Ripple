<?php

declare(strict_types=1);

namespace Ripple\CLI;

use Ripple\Analysis\Index\RepositoryIndexHolder;
use Ripple\Analysis\Semantics\CompositeSemanticAnnotationProvider;
use Ripple\Analysis\Semantics\JsonSemanticAnnotationProvider;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticAnnotationProvider;
use Ripple\Analysis\Semantics\RippleConfigurationLoader;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\SemanticAnnotationProvider;
use Ripple\Analysis\Semantics\StaticSemanticAnnotationProvider;

final class ConfiguredSemanticAnnotationProvider implements SemanticAnnotationProvider
{
    public function __construct(
        private readonly string $workingDirectory,
        private readonly RepositoryIndexHolder $indexHolder,
        private readonly RippleConfigurationLoader $loader = new RippleConfigurationLoader(),
    ) {
    }

    /**
     * @return list<SemanticAnnotation>
     */
    public function getAnnotations(): array
    {
        $path = JsonSemanticAnnotationProvider::pathForWorkingDirectory($this->workingDirectory);
        $config = $this->loader->load($path);
        $providers = [new StaticSemanticAnnotationProvider($config->semanticAnnotations)];
        if ($config->usesLaravel()) {
            $providers[] = new LaravelSemanticAnnotationProvider($this->indexHolder);
        }

        return (new CompositeSemanticAnnotationProvider($providers))->getAnnotations();
    }
}
