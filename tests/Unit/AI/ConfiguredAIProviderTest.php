<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIConfigurationLoader;
use Ripple\AI\AIProviderFactory;
use Ripple\AI\AIRequest;
use Ripple\AI\ConfiguredAIProvider;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\Tests\Support\FakeAIHttpClient;
use Ripple\Tests\Support\TemporaryDirectory;

final class ConfiguredAIProviderTest extends TestCase
{
    public function testDoesNotCreateTheVendorProviderUntilGenerateIsCalled(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('ripple.json', json_encode([
            'ai' => [
                'enabled' => true,
                'provider' => 'openai',
                'model' => 'test-model',
            ],
        ], JSON_THROW_ON_ERROR));
        $http = new FakeAIHttpClient(new AIHttpResponse(200, '{"output_text":"ok"}'));
        $provider = new ConfiguredAIProvider(
            new AIConfigurationLoader(),
            new AIProviderFactory($http, 'sk-test-secret-key-do-not-leak'),
            $directory->path,
        );

        $this->assertSame([], $http->requests);

        $response = $provider->generate(new AIRequest('Facts.'));

        $this->assertTrue($response->wasGenerated());
        $this->assertSame('ok', $response->text);
        $this->assertCount(1, $http->requests);
    }

    public function testDisabledConfigurationDoesNotSendHttp(): void
    {
        $directory = TemporaryDirectory::create();
        $http = new FakeAIHttpClient(new AIHttpResponse(200, '{"output_text":"should not appear"}'));
        $provider = new ConfiguredAIProvider(
            new AIConfigurationLoader(),
            new AIProviderFactory($http, 'sk-test-secret-key-do-not-leak'),
            $directory->path,
        );

        $response = $provider->generate(new AIRequest('Facts.'));

        $this->assertFalse($response->wasGenerated());
        $this->assertSame([], $http->requests);
    }
}
