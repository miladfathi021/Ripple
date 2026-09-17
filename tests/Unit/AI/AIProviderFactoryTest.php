<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIConfiguration;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIProviderFactory;
use Ripple\AI\AIRequest;
use Ripple\AI\CodeCraft\CodeCraftProvider;
use Ripple\AI\EnvironmentAIApiKey;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\AI\NullAIProvider;
use Ripple\AI\OpenAI\OpenAIProvider;
use Ripple\Tests\Support\FakeAIHttpClient;

final class AIProviderFactoryTest extends TestCase
{
    private const API_KEY = 'sk-test-secret-key-do-not-leak';

    public function testDisabledConfigurationReturnsNullProviderWithoutHttp(): void
    {
        $http = $this->http();
        $provider = (new AIProviderFactory($http, self::API_KEY))->create(AIConfiguration::disabled());

        $this->assertInstanceOf(NullAIProvider::class, $provider);
        $this->assertFalse($provider->generate(new AIRequest('Facts.'))->wasGenerated());
        $this->assertSame([], $http->requests);
    }

    public function testOpenAiConfigurationCreatesTheOpenAiProvider(): void
    {
        $http = $this->http();
        $provider = (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
            enabled: true,
            provider: 'openai',
            model: 'test-model',
            timeoutSeconds: 15,
        ));

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $response = $provider->generate(new AIRequest('Facts.'));
        $this->assertSame('generated text', $response->text);
        $this->assertSame(15, $http->requests[0]->timeoutSeconds);
        $this->assertSame('test-model', json_decode($http->requests[0]->body, true)['model']);
        $this->assertSame(OpenAIProvider::ENDPOINT, $http->requests[0]->url);
    }

    public function testOpenAiIgnoresConfiguredBaseUrl(): void
    {
        $http = $this->http();
        $provider = (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
            enabled: true,
            provider: 'openai',
            model: 'test-model',
            baseUrl: 'https://www.codecraftapi.com/v1',
        ));

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $provider->generate(new AIRequest('Facts.'));
        $this->assertSame(OpenAIProvider::ENDPOINT, $http->requests[0]->url);
    }

    public function testCodeCraftConfigurationCreatesTheCodeCraftProvider(): void
    {
        $http = $this->codeCraftHttp();
        $provider = (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
            enabled: true,
            provider: 'codecraft',
            model: 'test-model',
            timeoutSeconds: 15,
        ));

        $this->assertInstanceOf(CodeCraftProvider::class, $provider);
        $response = $provider->generate(new AIRequest('Facts.'));
        $this->assertSame('Ripple works', $response->text);
        $this->assertSame(15, $http->requests[0]->timeoutSeconds);
        $this->assertSame('https://www.codecraftapi.com/v1/chat/completions', $http->requests[0]->url);
        $this->assertSame('test-model', json_decode($http->requests[0]->body, true)['model']);
    }

    public function testCodeCraftUsesConfiguredBaseUrl(): void
    {
        $http = $this->codeCraftHttp();
        $provider = (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
            enabled: true,
            provider: 'codecraft',
            model: 'test-model',
            baseUrl: 'https://example.test/v1/',
        ));

        $provider->generate(new AIRequest('Facts.'));
        $this->assertSame('https://example.test/v1/chat/completions', $http->requests[0]->url);
    }

    public function testCodeCraftMissingModelFailsBeforeHttp(): void
    {
        $http = $this->codeCraftHttp();

        try {
            (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
                enabled: true,
                provider: 'codecraft',
            ));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('CodeCraft model is not configured. Set "ai.model" in ripple.json.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testMissingModelFailsBeforeHttp(): void
    {
        $http = $this->http();

        try {
            (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
                enabled: true,
                provider: 'openai',
            ));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('OpenAI model is not configured. Set "ai.model" in ripple.json.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testMissingApiKeyFailsBeforeHttp(): void
    {
        $http = $this->http();

        try {
            (new AIProviderFactory($http, ''))->create(new AIConfiguration(
                enabled: true,
                provider: 'openai',
                model: 'test-model',
            ));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame("OpenAI API key is not configured.\nSet RIPPLE_AI_API_KEY.", $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testUnknownProviderFailsBeforeHttp(): void
    {
        $http = $this->http();

        try {
            (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(
                enabled: true,
                provider: 'anthropic',
                model: 'test-model',
            ));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('Unsupported AI provider "anthropic".', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testEnabledWithoutProviderFailsBeforeHttp(): void
    {
        $http = $this->http();

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('AI is enabled but no provider is configured.');

        (new AIProviderFactory($http, self::API_KEY))->create(new AIConfiguration(enabled: true));
    }

    public function testApiKeyIsReadFromTheEnvironmentWhenNotInjected(): void
    {
        $previous = getenv(EnvironmentAIApiKey::NAME);
        putenv(EnvironmentAIApiKey::NAME . '=' . self::API_KEY);
        try {
            $http = $this->http();
            $provider = (new AIProviderFactory($http))->create(new AIConfiguration(
                enabled: true,
                provider: 'openai',
                model: 'test-model',
            ));
            $this->assertInstanceOf(OpenAIProvider::class, $provider);
            $provider->generate(new AIRequest('Facts.'));
            $this->assertSame('Bearer ' . self::API_KEY, $http->requests[0]->headers['Authorization']);
        } finally {
            if (is_string($previous)) {
                putenv(EnvironmentAIApiKey::NAME . '=' . $previous);
            } else {
                putenv(EnvironmentAIApiKey::NAME);
            }
        }
    }

    public function testDisabledConfigurationDoesNotReadTheEnvironmentKey(): void
    {
        $previous = getenv(EnvironmentAIApiKey::NAME);
        putenv(EnvironmentAIApiKey::NAME);
        try {
            $provider = (new AIProviderFactory($this->http()))->create(AIConfiguration::disabled());
            $this->assertInstanceOf(NullAIProvider::class, $provider);
        } finally {
            if (is_string($previous)) {
                putenv(EnvironmentAIApiKey::NAME . '=' . $previous);
            } else {
                putenv(EnvironmentAIApiKey::NAME);
            }
        }
    }

    private function http(): FakeAIHttpClient
    {
        return new FakeAIHttpClient(new AIHttpResponse(200, json_encode([
            'output_text' => 'generated text',
        ], JSON_THROW_ON_ERROR)));
    }

    private function codeCraftHttp(): FakeAIHttpClient
    {
        return new FakeAIHttpClient(new AIHttpResponse(200, json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Ripple works',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR)));
    }
}
