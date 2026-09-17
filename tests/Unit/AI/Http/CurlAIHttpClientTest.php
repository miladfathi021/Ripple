<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI\Http;

use PHPUnit\Framework\TestCase;

final class CurlAIHttpClientTest extends TestCase
{
    public function testDoesNotCallDeprecatedCurlClose(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/AI/Http/CurlAIHttpClient.php');

        $this->assertStringNotContainsString('curl_close(', $source);
        $this->assertStringContainsString('curl_exec($handle)', $source);
        $this->assertStringContainsString('curl_errno($handle)', $source);
        $this->assertStringContainsString('curl_getinfo($handle, CURLINFO_RESPONSE_CODE)', $source);
    }
}
