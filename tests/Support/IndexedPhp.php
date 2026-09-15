<?php

declare(strict_types=1);

namespace Ripple\Tests\Support;

use Ripple\Analysis\Index\RepositoryIndex;
use Ripple\Analysis\Index\RepositoryIndexBuilder;
use Ripple\Analysis\Index\RepositoryIndexHolder;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticAnnotationProvider;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticContext;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticRule;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class IndexedPhp
{
    /**
     * @param array<string, string> $files
     */
    public static function index(array $files): RepositoryIndex
    {
        $directory = TemporaryDirectory::create();
        foreach ($files as $path => $contents) {
            $directory->write($path, $contents);
        }

        return (new RepositoryIndexBuilder())->build($directory->path);
    }

    public static function context(array $files): LaravelSemanticContext
    {
        return LaravelSemanticContext::fromIndex(self::index($files));
    }

    /**
     * @param list<LaravelSemanticRule> $rules
     * @return list<array{string, string}>
     */
    public static function annotations(array $files, array $rules): array
    {
        $holder = new RepositoryIndexHolder();
        $holder->set(self::index($files));
        $provider = new LaravelSemanticAnnotationProvider($holder, $rules);

        return array_map(
            static fn (SemanticAnnotation $annotation): array => [
                $annotation->symbolId,
                $annotation->type->value,
            ],
            $provider->getAnnotations(),
        );
    }
}
