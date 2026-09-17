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

    public function testEnabledFlagCanBeTrueWithoutSelectingAProvider(): void
    {
        $configuration = $this->load('{"ai": {"enabled": true}}');

        $this->assertTrue($configuration->isEnabled());
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
