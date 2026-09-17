<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI\CodeCraft;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIRequest;
use Ripple\AI\CodeCraft\CodeCraftProvider;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\Tests\Support\FakeAIHttpClient;

final class CodeCraftProviderTest extends TestCase
{
    private const API_KEY = 'cc-test-secret-key-do-not-leak';

    private const MODEL = 'test-model';

    public function testSuccessfulResponseSendsChatCompletionsRequestAndExtractsText(): void
    {
        $http = $this->http($this->body('Ripple works'));
        $response = $this->provider($http)->generate(
            new AIRequest('Structured analysis facts.', 'Write a short explanation.'),
        );

        $this->assertTrue($response->wasGenerated());
        $this->assertSame('Ripple works', $response->text);
        $this->assertCount(1, $http->requests);

        $sent = $http->requests[0];
        $this->assertSame('POST', $sent->method);
        $this->assertSame('https://www.codecraftapi.com/v1/chat/completions', $sent->url);
        $this->assertSame('Bearer ' . self::API_KEY, $sent->headers['Authorization']);
        $this->assertSame('application/json', $sent->headers['Content-Type']);
        $this->assertSame(30, $sent->timeoutSeconds);

        $payload = json_decode($sent->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::MODEL, $payload['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => 'Write a short explanation.'],
            ['role' => 'user', 'content' => 'Structured analysis facts.'],
        ], $payload['messages']);
        $this->assertSame(['model', 'messages'], array_keys($payload));
        $this->assertStringNotContainsString(self::API_KEY, $sent->body);
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertArrayNotHasKey('Authorization', $payload);
    }

    public function testEmptyInstructionsSendOnlyTheUserMessage(): void
    {
        $http = $this->http($this->body('ok'));
        $this->provider($http)->generate(new AIRequest('Facts only.'));

        $payload = json_decode($http->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            ['role' => 'user', 'content' => 'Facts only.'],
        ], $payload['messages']);
    }

    public function testCustomBaseUrlIsNormalizedAndUsed(): void
    {
        $http = $this->http($this->body('ok'));
        $this->provider($http, 30, 'https://example.test/v1/')->generate(new AIRequest('Facts.'));

        $this->assertSame('https://example.test/v1/chat/completions', $http->requests[0]->url);
    }

    public function testInvalidBaseUrlIsRejectedWithoutHttp(): void
    {
        $http = $this->http($this->body('should not be requested'));

        try {
            new CodeCraftProvider(self::API_KEY, self::MODEL, $http, 30, 'http://example.test/v1');
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('CodeCraft base URL must be an https URL.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    #[DataProvider('httpFailureStatuses')]
    public function testHttpFailuresBecomeProviderExceptions(int $status, string $message): void
    {
        $http = $this->http('{"error":{"message":"' . self::API_KEY . '"}}', $status);

        try {
            $this->provider($http)->generate(new AIRequest('Facts.'));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('Authorization', $exception->getMessage());
            $this->assertStringNotContainsString('Bearer', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function httpFailureStatuses(): array
    {
        return [
            '400' => [400, 'CodeCraft rejected the request.'],
            '401' => [401, 'CodeCraft authentication failed.'],
            '403' => [403, 'CodeCraft request was forbidden.'],
            '404' => [404, 'CodeCraft endpoint was not found.'],
            '408' => [408, 'CodeCraft request timed out.'],
            '429' => [429, 'CodeCraft rate limit exceeded.'],
            '500' => [500, 'CodeCraft is unavailable.'],
            '502' => [502, 'CodeCraft is unavailable.'],
            '503' => [503, 'CodeCraft is unavailable.'],
        ];
    }

    public function testInvalidJsonBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('CodeCraft returned an invalid response.');

        $this->provider($this->http('{not-json', 200))->generate(new AIRequest('Facts.'));
    }

    public function testMissingChoicesBecomeAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('CodeCraft returned an unexpected response.');

        $this->provider($this->http('{"id":"chatcmpl_123"}', 200))->generate(new AIRequest('Facts.'));
    }

    public function testEmptyChoicesBecomeAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('CodeCraft returned an unexpected response.');

        $this->provider($this->http('{"choices":[]}', 200))->generate(new AIRequest('Facts.'));
    }

    public function testMissingMessageBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('CodeCraft returned an unexpected response.');

        $this->provider($this->http('{"choices":[{}]}', 200))->generate(new AIRequest('Facts.'));
    }

    public function testMissingContentBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('CodeCraft returned an unexpected response.');

        $this->provider($this->http('{"choices":[{"message":{"role":"assistant"}}]}', 200))->generate(new AIRequest('Facts.'));
    }

    public function testEmptyContentBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('CodeCraft returned empty output.');

        $this->provider($this->http($this->body('   '), 200))->generate(new AIRequest('Facts.'));
    }

    public function testSimulatedConnectionFailureDoesNotExposeTheApiKey(): void
    {
        $http = new FakeAIHttpClient(new AIProviderException('AI request failed. ' . self::API_KEY));

        try {
            $this->provider($http)->generate(new AIRequest('Facts.'));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('CodeCraft request failed.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function testSimulatedTimeoutDoesNotExposeTheApiKey(): void
    {
        $http = new FakeAIHttpClient(new AIProviderException('AI request timed out.'));

        try {
            $this->provider($http)->generate(new AIRequest('Facts.'));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('CodeCraft request timed out.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function testEmptyApiKeyIsRejectedWithoutNetwork(): void
    {
        $http = $this->http($this->body('should not be requested'));

        try {
            new CodeCraftProvider('', self::MODEL, $http, 30);
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame("CodeCraft API key is not configured.\nSet RIPPLE_AI_API_KEY.", $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testEmptyModelIsRejectedWithoutNetwork(): void
    {
        $http = $this->http($this->body('should not be requested'));

        try {
            new CodeCraftProvider(self::API_KEY, '', $http, 30);
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('CodeCraft model is not configured. Set "ai.model" in ripple.json.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testConfigurableTimeoutIsSentToTheHttpClient(): void
    {
        $http = $this->http($this->body('ok'));
        $this->provider($http, 12)->generate(new AIRequest('Facts.'));

        $this->assertSame(12, $http->requests[0]->timeoutSeconds);
    }

    private function provider(
        FakeAIHttpClient $http,
        int $timeoutSeconds = 30,
        string $baseUrl = CodeCraftProvider::DEFAULT_BASE_URL,
    ): CodeCraftProvider {
        return new CodeCraftProvider(self::API_KEY, self::MODEL, $http, $timeoutSeconds, $baseUrl);
    }

    private function http(string $body, int $status = 200): FakeAIHttpClient
    {
        return new FakeAIHttpClient(new AIHttpResponse($status, $body));
    }

    private function body(string $text): string
    {
        return json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $text,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
