<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\Analysis\Semantics\InvalidSemanticConfigurationException;
use Ripple\Analysis\Semantics\JsonSemanticAnnotationProvider;
use Ripple\Tests\Support\TemporaryDirectory;

final class JsonSemanticAnnotationProviderTest extends TestCase
{
    public function testValidConfigurationIsLoadedDeterministically(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('ripple.json', <<<'JSON'
{
  "semantic_annotations": [
    {
      "symbol": "App\\Integrations\\StripeClient::charge",
      "type": "external_integration"
    },
    {
      "symbol": "App\\Http\\Controllers\\ReservationController::update",
      "type": "api_entrypoint"
    }
  ]
}
JSON);

        $annotations = JsonSemanticAnnotationProvider::forWorkingDirectory($directory->path)->getAnnotations();

        $this->assertSame(
            [
                ['App\\Http\\Controllers\\ReservationController::update', 'api_entrypoint'],
                ['App\\Integrations\\StripeClient::charge', 'external_integration'],
            ],
            $this->snapshots($annotations),
        );
    }

    public function testMissingConfigurationFileYieldsNoAnnotations(): void
    {
        $provider = new JsonSemanticAnnotationProvider('/tmp/ripple-missing-' . bin2hex(random_bytes(8)) . '/ripple.json');

        $this->assertSame([], $provider->getAnnotations());
    }

    public function testMalformedJsonFails(): void
    {
        $provider = $this->providerWithContents('{not json');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('Invalid JSON:');
        $provider->getAnnotations();
    }

    public function testMissingSemanticAnnotationsKeyFails(): void
    {
        $provider = $this->providerWithContents('{"other": []}');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('Missing required key "semantic_annotations".');
        $provider->getAnnotations();
    }

    public function testMissingSymbolFails(): void
    {
        $provider = $this->providerWithContents('{"semantic_annotations": [{"type": "queue"}]}');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('semantic_annotations[0] is missing required key "symbol".');
        $provider->getAnnotations();
    }

    public function testMissingTypeFails(): void
    {
        $provider = $this->providerWithContents('{"semantic_annotations": [{"symbol": "A::run"}]}');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('semantic_annotations[0] is missing required key "type".');
        $provider->getAnnotations();
    }

    public function testInvalidSemanticTypeFails(): void
    {
        $provider = $this->providerWithContents(
            '{"semantic_annotations": [{"symbol": "PaymentRepository", "type": "database"}]}',
        );

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('Invalid semantic type "database".');
        $provider->getAnnotations();
    }

    public function testUnknownSymbolIdIsAllowed(): void
    {
        $provider = $this->providerWithContents(
            '{"semantic_annotations": [{"symbol": "App\\\\Future\\\\NotIndexed::handle", "type": "queue"}]}',
        );

        $annotations = $provider->getAnnotations();

        $this->assertCount(1, $annotations);
        $this->assertSame('App\\Future\\NotIndexed::handle', $annotations[0]->symbolId);
        $this->assertSame(FlowSemanticType::Queue, $annotations[0]->type);
    }

    public function testDuplicateAnnotationsAreDeterministic(): void
    {
        $provider = $this->providerWithContents(<<<'JSON'
{
  "semantic_annotations": [
    {"symbol": "B::run", "type": "queue"},
    {"symbol": "A::run", "type": "event"},
    {"symbol": "B::run", "type": "queue"},
    {"symbol": "A::run", "type": "authentication"}
  ]
}
JSON);

        $first = $this->snapshots($provider->getAnnotations());
        $second = $this->snapshots($provider->getAnnotations());

        $this->assertSame($first, $second);
        $this->assertSame(
            [
                ['A::run', 'authentication'],
                ['A::run', 'event'],
                ['B::run', 'queue'],
            ],
            $first,
        );
    }

    public function testJsonArrayRootFails(): void
    {
        $provider = $this->providerWithContents('[]');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('Semantic configuration must be a JSON object.');
        $provider->getAnnotations();
    }

    public function testEmptyObjectWithoutAnnotationsFails(): void
    {
        $provider = $this->providerWithContents('{}');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('Missing required key "semantic_annotations".');
        $provider->getAnnotations();
    }

    public function testFrameworkLaravelWithoutAnnotationsIsValid(): void
    {
        $provider = $this->providerWithContents('{"framework": "laravel"}');

        $this->assertSame([], $provider->getAnnotations());
    }

    public function testAiOnlyConfigurationDoesNotRequireSemanticAnnotations(): void
    {
        $provider = $this->providerWithContents('{"ai": {"enabled": false}}');

        $this->assertSame([], $provider->getAnnotations());
    }

    public function testSemanticAnnotationsCanCoexistWithAiSettings(): void
    {
        $provider = $this->providerWithContents(<<<'JSON'
{
  "ai": {"enabled": false},
  "semantic_annotations": [
    {"symbol": "App\\Something::method", "type": "queue"}
  ]
}
JSON);

        $annotations = $provider->getAnnotations();
        $this->assertCount(1, $annotations);
        $this->assertSame('App\\Something::method', $annotations[0]->symbolId);
        $this->assertSame(FlowSemanticType::Queue, $annotations[0]->type);
    }

    public function testFrameworkLaravelCombinesWithExplicitAnnotations(): void
    {
        $provider = $this->providerWithContents(<<<'JSON'
{
  "framework": "laravel",
  "semantic_annotations": [
    {"symbol": "App\\Something::method", "type": "authentication"}
  ]
}
JSON);

        $annotations = $provider->getAnnotations();
        $this->assertCount(1, $annotations);
        $this->assertSame('App\\Something::method', $annotations[0]->symbolId);
        $this->assertSame(FlowSemanticType::Authentication, $annotations[0]->type);
    }

    public function testInvalidFrameworkValueFails(): void
    {
        $provider = $this->providerWithContents('{"framework": 1}');

        $this->expectException(InvalidSemanticConfigurationException::class);
        $this->expectExceptionMessage('"framework" must be a non-empty string.');
        $provider->getAnnotations();
    }

    private function providerWithContents(string $contents): JsonSemanticAnnotationProvider
    {
        $directory = TemporaryDirectory::create();
        $directory->write('ripple.json', $contents);

        return JsonSemanticAnnotationProvider::forWorkingDirectory($directory->path);
    }

    /**
     * @param list<\Ripple\Analysis\Semantics\SemanticAnnotation> $annotations
     * @return list<array{string, string}>
     */
    private function snapshots(array $annotations): array
    {
        return array_map(
            static fn ($annotation): array => [$annotation->symbolId, $annotation->type->value],
            $annotations,
        );
    }
}
