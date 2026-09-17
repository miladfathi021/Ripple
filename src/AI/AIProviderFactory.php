<?php

declare(strict_types=1);

namespace Ripple\AI;

use Ripple\AI\CodeCraft\CodeCraftProvider;
use Ripple\AI\Http\AIHttpClient;
use Ripple\AI\Http\CurlAIHttpClient;
use Ripple\AI\OpenAI\OpenAIProvider;

final class AIProviderFactory
{
    public function __construct(
        private readonly AIHttpClient $httpClient = new CurlAIHttpClient(),
        private readonly ?string $apiKey = null,
    ) {
    }

    public function create(AIConfiguration $configuration): AIProvider
    {
        if (!$configuration->isEnabled()) {
            return new NullAIProvider();
        }

        $provider = $configuration->provider;
        if ($provider === null || $provider === '') {
            throw new AIProviderException('AI is enabled but no provider is configured.');
        }

        return match ($provider) {
            'openai' => $this->openAi($configuration),
            'codecraft' => $this->codeCraft($configuration),
            default => throw new AIProviderException('Unsupported AI provider "' . $provider . '".'),
        };
    }

    private function openAi(AIConfiguration $configuration): OpenAIProvider
    {
        $model = $configuration->model;
        if ($model === null || $model === '') {
            throw new AIProviderException('OpenAI model is not configured. Set "ai.model" in ripple.json.');
        }

        $apiKey = $this->apiKey ?? EnvironmentAIApiKey::read();
        if ($apiKey === null || $apiKey === '') {
            throw new AIProviderException("OpenAI API key is not configured.\nSet RIPPLE_AI_API_KEY.");
        }

        return new OpenAIProvider(
            $apiKey,
            $model,
            $this->httpClient,
            $configuration->timeoutSeconds,
        );
    }

    private function codeCraft(AIConfiguration $configuration): CodeCraftProvider
    {
        $model = $configuration->model;
        if ($model === null || $model === '') {
            throw new AIProviderException('CodeCraft model is not configured. Set "ai.model" in ripple.json.');
        }

        $apiKey = $this->apiKey ?? EnvironmentAIApiKey::read();
        if ($apiKey === null || $apiKey === '') {
            throw new AIProviderException("CodeCraft API key is not configured.\nSet RIPPLE_AI_API_KEY.");
        }

        return new CodeCraftProvider(
            $apiKey,
            $model,
            $this->httpClient,
            $configuration->timeoutSeconds,
            $configuration->baseUrl ?? CodeCraftProvider::DEFAULT_BASE_URL,
        );
    }
}
