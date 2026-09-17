<?php

declare(strict_types=1);

namespace Ripple\AI;

final class ConfiguredAIProvider implements AIProvider
{
    private ?AIProvider $resolved = null;

    public function __construct(
        private readonly AIConfigurationLoader $configurationLoader,
        private readonly AIProviderFactory $providerFactory,
        private readonly string $workingDirectory,
    ) {
    }

    public function generate(AIRequest $request): AIResponse
    {
        return $this->provider()->generate($request);
    }

    private function provider(): AIProvider
    {
        return $this->resolved ??= $this->providerFactory->create(
            $this->configurationLoader->load(
                AIConfigurationLoader::pathForWorkingDirectory($this->workingDirectory),
            ),
        );
    }
}
