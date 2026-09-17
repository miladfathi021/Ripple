<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI\OpenAI;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIRequest;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\AI\OpenAI\OpenAIProvider;
use Ripple\Tests\Support\FakeAIHttpClient;

final class OpenAIProviderTest extends TestCase
{
    private const API_KEY = 'sk-test-secret-key-do-not-leak';

    private const MODEL = 'test-model';

    public function testSuccessfulResponseSendsTheExpectedRequestAndExtractsText(): void
    {
        $http = $this->http($this->responseBody('This change may affect the reservation update flow.'));
        $provider = $this->provider($http);
        $request = new AIRequest('Structured analysis facts.', 'Write a short explanation.');

        $response = $provider->generate($request);

        $this->assertTrue($response->wasGenerated());
        $this->assertSame('This change may affect the reservation update flow.', $response->text);
        $this->assertCount(1, $http->requests);

        $sent = $http->requests[0];
        $this->assertSame('POST', $sent->method);
        $this->assertSame(OpenAIProvider::ENDPOINT, $sent->url);
        $this->assertSame('https://api.openai.com/v1/responses', $sent->url);
        $this->assertSame('Bearer ' . self::API_KEY, $sent->headers['Authorization']);
        $this->assertSame('application/json', $sent->headers['Content-Type']);
        $this->assertSame(30, $sent->timeoutSeconds);

        $payload = json_decode($sent->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::MODEL, $payload['model']);
        $this->assertSame('Structured analysis facts.', $payload['input']);
        $this->assertSame('Write a short explanation.', $payload['instructions']);
        $this->assertFalse($payload['store']);
        $this->assertSame(['model', 'input', 'store', 'instructions'], array_keys($payload));
    }

    public function testEmptyInstructionsAreOmittedFromTheRequestBody(): void
    {
        $http = $this->http($this->responseBody('ok'));
        $this->provider($http)->generate(new AIRequest('Facts only.'));

        $payload = json_decode($http->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['store']);
        $this->assertSame(['model', 'input', 'store'], array_keys($payload));
    }

    public function testOutputArrayIsUsedWhenOutputTextIsMissing(): void
    {
        $http = $this->http(json_encode([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Hello '],
                        ['type' => 'output_text', 'text' => 'world'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $this->provider($http)->generate(new AIRequest('Facts.'));

        $this->assertSame('Hello world', $response->text);
    }

    public function testEmptyRootOutputTextFallsBackToTheRestOutputArray(): void
    {
        $http = $this->http(json_encode([
            'output_text' => '',
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'From REST output'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $this->provider($http)->generate(new AIRequest('Facts.'));

        $this->assertSame('From REST output', $response->text);
    }

    public function testWhitespaceRootOutputTextFallsBackToTheRestOutputArray(): void
    {
        $http = $this->http(json_encode([
            'output_text' => "  \n",
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'From REST output'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $this->provider($http)->generate(new AIRequest('Facts.'));

        $this->assertSame('From REST output', $response->text);
    }

    public function testFailedResponseStatusIsNotReturnedAsGeneratedText(): void
    {
        $http = $this->http(json_encode([
            'status' => 'failed',
            'output_text' => 'partial text that must not be used',
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'partial text that must not be used'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->provider($http)->generate(new AIRequest('Facts.'));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('OpenAI returned a failed response.', $exception->getMessage());
            $this->assertStringNotContainsString('partial text that must not be used', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function testIncompleteResponseStatusIsNotReturnedAsGeneratedText(): void
    {
        $http = $this->http(json_encode([
            'status' => 'incomplete',
            'output_text' => 'partial text that must not be used',
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'partial text that must not be used'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->provider($http)->generate(new AIRequest('Facts.'));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('OpenAI returned an incomplete response.', $exception->getMessage());
            $this->assertStringNotContainsString('partial text that must not be used', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
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
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function httpFailureStatuses(): array
    {
        return [
            '400' => [400, 'OpenAI rejected the request.'],
            '401' => [401, 'OpenAI authentication failed.'],
            '403' => [403, 'OpenAI request was forbidden.'],
            '429' => [429, 'OpenAI rate limit exceeded.'],
            '500' => [500, 'OpenAI is unavailable.'],
            '503' => [503, 'OpenAI is unavailable.'],
        ];
    }

    public function testInvalidJsonBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('OpenAI returned an invalid response.');

        $this->provider($this->http('{not-json', 200))->generate(new AIRequest('Facts.'));
    }

    public function testUnexpectedJsonShapeBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('OpenAI returned an unexpected response.');

        $this->provider($this->http('[]', 200))->generate(new AIRequest('Facts.'));
    }

    public function testMissingOutputBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('OpenAI returned empty output.');

        $this->provider($this->http('{"id":"resp_123"}', 200))->generate(new AIRequest('Facts.'));
    }

    public function testEmptyOutputTextBecomesAProviderException(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('OpenAI returned empty output.');

        $this->provider($this->http($this->responseBody('   '), 200))->generate(new AIRequest('Facts.'));
    }

    public function testSimulatedConnectionFailureDoesNotExposeTheApiKey(): void
    {
        $http = new FakeAIHttpClient(new AIProviderException('AI request failed. ' . self::API_KEY));

        try {
            $this->provider($http)->generate(new AIRequest('Facts.'));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('OpenAI request failed.', $exception->getMessage());
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
            $this->assertSame('OpenAI request timed out.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function testEmptyApiKeyIsRejectedWithoutNetwork(): void
    {
        $http = $this->http($this->responseBody('should not be requested'));

        try {
            new OpenAIProvider('', self::MODEL, $http, 30);
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame("OpenAI API key is not configured.\nSet RIPPLE_AI_API_KEY.", $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testEmptyModelIsRejectedWithoutNetwork(): void
    {
        $http = $this->http($this->responseBody('should not be requested'));

        try {
            new OpenAIProvider(self::API_KEY, '', $http, 30);
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $exception) {
            $this->assertSame('OpenAI model is not configured. Set "ai.model" in ripple.json.', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame([], $http->requests);
    }

    public function testConfigurableTimeoutIsSentToTheHttpClient(): void
    {
        $http = $this->http($this->responseBody('ok'));
        $this->provider($http, 12)->generate(new AIRequest('Facts.'));

        $this->assertSame(12, $http->requests[0]->timeoutSeconds);
    }

    private function provider(FakeAIHttpClient $http, int $timeoutSeconds = 30): OpenAIProvider
    {
        return new OpenAIProvider(self::API_KEY, self::MODEL, $http, $timeoutSeconds);
    }

    private function http(string $body, int $status = 200): FakeAIHttpClient
    {
        return new FakeAIHttpClient(new AIHttpResponse($status, $body));
    }

    private function responseBody(string $text): string
    {
        return json_encode([
            'output_text' => $text,
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => $text],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
