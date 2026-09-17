<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIConfiguration;
use Ripple\AI\AIConfigurationLoader;
use Ripple\AI\AIProviderException;
use Ripple\Tests\Support\TemporaryDirectory;

final class AIConfigurationLoaderTest extends TestCase
{
    public function testDefaultConfigurationIsDisabled(): void
    {
        $configuration = new AIConfiguration();

        $this->assertFalse($configuration->enabled);
        $this->assertFalse($configuration->isEnabled());
        $this->assertFalse(AIConfiguration::disabled()->isEnabled());
    }

    public function testMissingFileDisablesAI(): void
    {
        $loader = new AIConfigurationLoader();

        $configuration = $loader->load(
            sys_get_temp_dir() . '/ripple-missing-' . bin2hex(random_bytes(8)) . '/ripple.json',
        );

        $this->assertFalse($configuration->isEnabled());
    }

    public function testMissingAiKeyDisablesAI(): void
    {
        $configuration = $this->load('{"semantic_annotations": []}');

        $this->assertFalse($configuration->isEnabled());
    }

    public function testExplicitlyDisabledAiRemainsDisabled(): void
    {
        $configuration = $this->load('{"ai": {"enabled": false}}');

        $this->assertFalse($configuration->isEnabled());
    }

    public function testEmptyAiObjectDefaultsToDisabled(): void
    {
        $configuration = $this->load('{"ai": {}}');

        $this->assertFalse($configuration->isEnabled());
    }

    public function testEnabledFlagRequiresAProvider(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('AI is enabled but no provider is configured.');

        $this->load('{"ai": {"enabled": true}}');
    }

    public function testEnabledOpenAiConfigurationLoadsProviderAndModel(): void
    {
        $configuration = $this->load('{"ai": {"enabled": true, "provider": "openai", "model": "test-model"}}');

        $this->assertTrue($configuration->isEnabled());
        $this->assertSame('openai', $configuration->provider);
        $this->assertSame('test-model', $configuration->model);
        $this->assertSame(30, $configuration->timeoutSeconds);
        $this->assertNull($configuration->baseUrl);
    }

    public function testEnabledCodeCraftConfigurationLoadsProviderModelAndBaseUrl(): void
    {
        $configuration = $this->load('{"ai": {"enabled": true, "provider": "codecraft", "model": "test-model", "base_url": "https://www.codecraftapi.com/v1"}}');

        $this->assertTrue($configuration->isEnabled());
        $this->assertSame('codecraft', $configuration->provider);
        $this->assertSame('test-model', $configuration->model);
        $this->assertSame('https://www.codecraftapi.com/v1', $configuration->baseUrl);
    }

    public function testEnabledOpenAiConfigurationCanOmitModelUntilProviderSelection(): void
    {
        $configuration = $this->load('{"ai": {"enabled": true, "provider": "openai"}}');

        $this->assertTrue($configuration->isEnabled());
        $this->assertSame('openai', $configuration->provider);
        $this->assertNull($configuration->model);
    }

    public function testTimeoutSecondsCanBeConfigured(): void
    {
        $configuration = $this->load('{"ai": {"enabled": true, "provider": "openai", "model": "test-model", "timeout_seconds": 12}}');

        $this->assertSame(12, $configuration->timeoutSeconds);
    }

    public function testEmptyBaseUrlFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai.base_url" must be a non-empty string.');

        $this->load('{"ai": {"enabled": true, "provider": "codecraft", "model": "test-model", "base_url": ""}}');
    }

    public function testOpenAiConfigurationIgnoresUnusedBaseUrlAtLoadTime(): void
    {
        $configuration = $this->load('{"ai": {"enabled": true, "provider": "openai", "model": "test-model", "base_url": "https://www.codecraftapi.com/v1"}}');

        $this->assertSame('openai', $configuration->provider);
        $this->assertSame('https://www.codecraftapi.com/v1', $configuration->baseUrl);
    }

    public function testNonStringProviderFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai.provider" must be a non-empty string.');

        $this->load('{"ai": {"enabled": true, "provider": 1}}');
    }

    public function testEmptyProviderFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai.provider" must be a non-empty string.');

        $this->load('{"ai": {"enabled": true, "provider": ""}}');
    }

    public function testInvalidModelFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai.model" must be a non-empty string.');

        $this->load('{"ai": {"enabled": true, "provider": "openai", "model": " "}}');
    }

    public function testInvalidTimeoutFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai.timeout_seconds" must be a positive integer.');

        $this->load('{"ai": {"enabled": true, "provider": "openai", "model": "test-model", "timeout_seconds": 0}}');
    }

    public function testInvalidAiValueFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai" must be an object.');

        $this->load('{"ai": true}');
    }

    public function testNonBooleanEnabledFlagFails(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('"ai.enabled" must be a boolean.');

        $this->load('{"ai": {"enabled": "yes"}}');
    }

    private function load(string $contents): AIConfiguration
    {
        $directory = TemporaryDirectory::create();
        $directory->write('ripple.json', $contents);

        return (new AIConfigurationLoader())->load(
            AIConfigurationLoader::pathForWorkingDirectory($directory->path),
        );
    }
}
