<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\CompositeSemanticAnnotationProvider;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\SemanticAnnotation;
use Ripple\Analysis\Semantics\StaticSemanticAnnotationProvider;

final class CompositeSemanticAnnotationProviderTest extends TestCase
{
    public function testMergesProvidersWithoutOverridingEarlierDuplicates(): void
    {
        $explicit = new StaticSemanticAnnotationProvider([
            new SemanticAnnotation('A::run', FlowSemanticType::Authentication),
            new SemanticAnnotation('B::run', FlowSemanticType::Queue),
        ]);
        $generated = new StaticSemanticAnnotationProvider([
            new SemanticAnnotation('A::run', FlowSemanticType::Authentication),
            new SemanticAnnotation('A::run', FlowSemanticType::DatabaseWrite),
            new SemanticAnnotation('C::run', FlowSemanticType::Event),
        ]);

        $annotations = (new CompositeSemanticAnnotationProvider([$explicit, $generated]))->getAnnotations();

        $this->assertSame(
            [
                ['A::run', 'authentication'],
                ['A::run', 'database_write'],
                ['B::run', 'queue'],
                ['C::run', 'event'],
            ],
            array_map(
                static fn (SemanticAnnotation $annotation): array => [
                    $annotation->symbolId,
                    $annotation->type->value,
                ],
                $annotations,
            ),
        );
    }
}
