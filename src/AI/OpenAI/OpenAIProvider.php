<?php

declare(strict_types=1);

namespace Ripple\AI\OpenAI;

use JsonException;
use Ripple\AI\AIProvider;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIRequest;
use Ripple\AI\AIResponse;
use Ripple\AI\Http\AIHttpClient;
use Ripple\AI\Http\AIHttpRequest;
use Ripple\AI\Http\AIHttpResponse;
use Throwable;

final class OpenAIProvider implements AIProvider
{
    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly AIHttpClient $httpClient,
        private readonly int $timeoutSeconds,
    ) {
        if (trim($this->apiKey) === '') {
            throw new AIProviderException("OpenAI API key is not configured.\nSet RIPPLE_AI_API_KEY.");
        }
        if (trim($this->model) === '') {
            throw new AIProviderException('OpenAI model is not configured. Set "ai.model" in ripple.json.');
        }
    }

    public function generate(AIRequest $request): AIResponse
    {
        $response = $this->send($this->httpRequest($request));
        $this->assertSuccessful($response);

        return AIResponse::generated($this->extractText($response->body));
    }

    private function httpRequest(AIRequest $request): AIHttpRequest
    {
        $payload = [
            'model' => $this->model,
            'input' => $request->input,
            'store' => false,
        ];
        if (trim($request->instructions) !== '') {
            $payload['instructions'] = $request->instructions;
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new AIProviderException('OpenAI request failed.');
        }

        return new AIHttpRequest(
            method: 'POST',
            url: self::ENDPOINT,
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
            throw new AIProviderException('OpenAI request failed.');
        }
    }

    private function transportMessage(string $message): string
    {
        return match ($message) {
            'AI request timed out.' => 'OpenAI request timed out.',
            default => 'OpenAI request failed.',
        };
    }

    private function assertSuccessful(AIHttpResponse $response): void
    {
        $status = $response->statusCode;
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new AIProviderException(match (true) {
            $status === 400 => 'OpenAI rejected the request.',
            $status === 401 => 'OpenAI authentication failed.',
            $status === 403 => 'OpenAI request was forbidden.',
            $status === 429 => 'OpenAI rate limit exceeded.',
            $status >= 500 => 'OpenAI is unavailable.',
            default => 'OpenAI request failed.',
        });
    }

    private function extractText(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AIProviderException('OpenAI returned an invalid response.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AIProviderException('OpenAI returned an unexpected response.');
        }

        $this->assertCompletedResponse($decoded);

        $text = $this->textFromPayload($decoded);
        if ($text === null || trim($text) === '') {
            throw new AIProviderException('OpenAI returned empty output.');
        }

        return $text;
    }

    /**
     * @param array<mixed> $payload
     */
    private function assertCompletedResponse(array $payload): void
    {
        $status = $payload['status'] ?? null;
        if (!is_string($status)) {
            return;
        }

        if ($status === 'failed') {
            throw new AIProviderException('OpenAI returned a failed response.');
        }

        if ($status === 'incomplete') {
            throw new AIProviderException('OpenAI returned an incomplete response.');
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function textFromPayload(array $payload): ?string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text']) && trim($payload['output_text']) !== '') {
            return $payload['output_text'];
        }

        $output = $payload['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        $chunks = [];
        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $type = $part['type'] ?? null;
                if ($type !== 'output_text' && $type !== 'text') {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $chunks[] = $part['text'];
                }
            }
        }

        if ($chunks === []) {
            return null;
        }

        return implode('', $chunks);
    }
}
