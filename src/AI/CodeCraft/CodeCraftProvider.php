<?php

declare(strict_types=1);

namespace Ripple\AI\CodeCraft;

use JsonException;
use Ripple\AI\AIProvider;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIRequest;
use Ripple\AI\AIResponse;
use Ripple\AI\Http\AIHttpClient;
use Ripple\AI\Http\AIHttpRequest;
use Ripple\AI\Http\AIHttpResponse;
use Throwable;

final class CodeCraftProvider implements AIProvider
{
    public const DEFAULT_BASE_URL = 'https://www.codecraftapi.com/v1';

    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly AIHttpClient $httpClient,
        private readonly int $timeoutSeconds,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        if (trim($this->apiKey) === '') {
            throw new AIProviderException("CodeCraft API key is not configured.\nSet RIPPLE_AI_API_KEY.");
        }
        if (trim($this->model) === '') {
            throw new AIProviderException('CodeCraft model is not configured. Set "ai.model" in ripple.json.');
        }

        $this->baseUrl = $this->normalizedBaseUrl($baseUrl);
    }

    public function generate(AIRequest $request): AIResponse
    {
        $response = $this->send($this->httpRequest($request));
        $this->assertSuccessful($response);

        return AIResponse::generated($this->extractText($response->body));
    }

    private function httpRequest(AIRequest $request): AIHttpRequest
    {
        $messages = [];
        if (trim($request->instructions) !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $request->instructions,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $request->input,
        ];

        try {
            $body = json_encode([
                'model' => $this->model,
                'messages' => $messages,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new AIProviderException('CodeCraft request failed.');
        }

        return new AIHttpRequest(
            method: 'POST',
            url: $this->completionsUrl(),
            headers: [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            body: $body,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    private function send(AIHttpRequest $request): AIHttpResponse
    {
        try {
            return $this->httpClient->send($request);
        } catch (AIProviderException $exception) {
            throw new AIProviderException($this->transportMessage($exception->getMessage()));
        } catch (Throwable) {
            throw new AIProviderException('CodeCraft request failed.');
        }
    }

    private function transportMessage(string $message): string
    {
        return match ($message) {
            'AI request timed out.' => 'CodeCraft request timed out.',
            default => 'CodeCraft request failed.',
        };
    }

    private function assertSuccessful(AIHttpResponse $response): void
    {
        $status = $response->statusCode;
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new AIProviderException(match (true) {
            $status === 400 => 'CodeCraft rejected the request.',
            $status === 401 => 'CodeCraft authentication failed.',
            $status === 403 => 'CodeCraft request was forbidden.',
            $status === 404 => 'CodeCraft endpoint was not found.',
            $status === 408 => 'CodeCraft request timed out.',
            $status === 429 => 'CodeCraft rate limit exceeded.',
            $status >= 500 => 'CodeCraft is unavailable.',
            default => 'CodeCraft request failed.',
        });
    }

    private function extractText(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AIProviderException('CodeCraft returned an invalid response.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AIProviderException('CodeCraft returned an unexpected response.');
        }

        $text = $this->textFromPayload($decoded);
        if ($text === null || trim($text) === '') {
            throw new AIProviderException('CodeCraft returned empty output.');
        }

        return $text;
    }

    /**
     * @param array<mixed> $payload
     */
    private function textFromPayload(array $payload): ?string
    {
        $choices = $payload['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            throw new AIProviderException('CodeCraft returned an unexpected response.');
        }

        $first = $choices[0] ?? null;
        if (!is_array($first) || !array_key_exists('message', $first) || !is_array($first['message'])) {
            throw new AIProviderException('CodeCraft returned an unexpected response.');
        }

        if (!array_key_exists('content', $first['message'])) {
            throw new AIProviderException('CodeCraft returned an unexpected response.');
        }

        $content = $first['message']['content'];
        if (!is_string($content)) {
            throw new AIProviderException('CodeCraft returned an unexpected response.');
        }

        return $content;
    }

    private function completionsUrl(): string
    {
        return $this->baseUrl . '/chat/completions';
    }

    private function normalizedBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        if ($trimmed === '') {
            throw new AIProviderException('CodeCraft base URL is not configured.');
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host']) || $parts['host'] === '') {
            throw new AIProviderException('CodeCraft base URL must be an https URL.');
        }

        return $trimmed;
    }
}
