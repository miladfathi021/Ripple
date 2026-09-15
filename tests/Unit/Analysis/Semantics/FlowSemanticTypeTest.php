<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\InvalidSemanticConfigurationException;
use ValueError;

final class FlowSemanticTypeTest extends TestCase
{
    #[DataProvider('supportedTypes')]
    public function testEverySupportedTypeHasAStableValue(FlowSemanticType $type, string $value): void
    {
        $this->assertSame($value, $type->value);
        $this->assertSame($type, FlowSemanticType::fromConfig($value));
        $this->assertSame($type, FlowSemanticType::tryFrom($value));
    }

    public function testValuesListEveryCase(): void
    {
        $this->assertSame(
            [
                'api_entrypoint',
                'database_read',
                'database_write',
                'queue',
                'event',
                'authentication',
                'external_integration',
            ],
            FlowSemanticType::values(),
        );
        $this->assertCount(count(FlowSemanticType::cases()), FlowSemanticType::values());
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->assertNull(FlowSemanticType::tryFrom('database'));
        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage(
            'Invalid semantic type "database". Expected one of: api_entrypoint, database_read, database_write, queue, event, authentication, external_integration.',
        );
        FlowSemanticType::fromConfig('database');
    }

    public function testNativeEnumFromRejectsUnknownValues(): void
    {
        $this->expectException(ValueError::class);
        FlowSemanticType::from('laravel_job');
    }

    /**
     * @return array<string, array{FlowSemanticType, string}>
     */
    public static function supportedTypes(): array
    {
        return [
            'api_entrypoint' => [FlowSemanticType::ApiEntrypoint, 'api_entrypoint'],
            'database_read' => [FlowSemanticType::DatabaseRead, 'database_read'],
            'database_write' => [FlowSemanticType::DatabaseWrite, 'database_write'],
            'queue' => [FlowSemanticType::Queue, 'queue'],
            'event' => [FlowSemanticType::Event, 'event'],
            'authentication' => [FlowSemanticType::Authentication, 'authentication'],
            'external_integration' => [FlowSemanticType::ExternalIntegration, 'external_integration'],
        ];
    }
}
