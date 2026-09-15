<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\InvalidSemanticAnnotationException;
use Ripple\Analysis\Semantics\SemanticAnnotation;

final class SemanticAnnotationTest extends TestCase
{
    public function testValidAnnotationExposesSymbolAndType(): void
    {
        $annotation = new SemanticAnnotation(
            'App\\Repositories\\PaymentRepository::update',
            FlowSemanticType::DatabaseWrite,
        );

        $this->assertSame('App\\Repositories\\PaymentRepository::update', $annotation->symbolId);
        $this->assertSame(FlowSemanticType::DatabaseWrite, $annotation->type);
        $this->assertSame('database_write', $annotation->type->value);
    }

    public function testEmptySymbolIsRejected(): void
    {
        $this->expectException(InvalidSemanticAnnotationException::class);
        $this->expectExceptionMessage('Semantic annotation symbol must be a non-empty string.');
        new SemanticAnnotation('', FlowSemanticType::Queue);
    }

    public function testWhitespaceOnlySymbolIsRejected(): void
    {
        $this->expectException(InvalidSemanticAnnotationException::class);
        $this->expectExceptionMessage('Semantic annotation symbol must be a non-empty string.');
        new SemanticAnnotation('   ', FlowSemanticType::Queue);
    }

    public function testPaddedSymbolIsRejected(): void
    {
        $this->expectException(InvalidSemanticAnnotationException::class);
        new SemanticAnnotation(' PaymentRepository::update ', FlowSemanticType::DatabaseWrite);
    }
}
