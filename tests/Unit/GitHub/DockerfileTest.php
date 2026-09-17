<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\GitHub;

use PHPUnit\Framework\TestCase;

final class DockerfileTest extends TestCase
{
    public function testImageStaysAMinimalPhpGitCli(): void
    {
        $dockerfile = $this->dockerfile();

        $this->assertStringContainsString('FROM php:8.3-cli-alpine', $dockerfile);
        $this->assertStringContainsString('apk add --no-cache git', $dockerfile);
        $this->assertStringContainsString('ENTRYPOINT ["php", "/opt/ripple/bin/ripple"]', $dockerfile);
        $this->assertStringContainsString('composer install --no-dev --classmap-authoritative --no-interaction --no-scripts', $dockerfile);
        $this->assertStringNotContainsString('COPY .env', $dockerfile);
        $this->assertStringNotContainsString('API_KEY', $dockerfile);
        $this->assertStringNotContainsString('OPENAI', $dockerfile);
        $this->assertStringNotContainsString('--privileged', $dockerfile);
        $this->assertStringNotContainsString('docker.sock', $dockerfile);
    }

    private function dockerfile(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');
        $this->assertNotFalse($contents);

        return $contents;
    }
}
