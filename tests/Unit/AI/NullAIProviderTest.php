<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProvider;
use Ripple\AI\AIRequest;
use Ripple\AI\AIResponse;
use Ripple\AI\NullAIProvider;

final class NullAIProviderTest extends TestCase
{
    public function testImplementsTheProviderContract(): void
    {
        $provider = new NullAIProvider();

        $this->assertInstanceOf(AIProvider::class, $provider);
        $response = $provider->generate(new AIRequest('Explain this result.'));
        $this->assertInstanceOf(AIResponse::class, $response);
    }

    public function testReturnsAnEmptyNonGeneratedResponseWithoutUsingTheInput(): void
    {
        $provider = new NullAIProvider();
        $response = $provider->generate(new AIRequest(
            input: 'This must not be echoed back.',
            instructions: 'Ignore this instruction.',
        ));

        $this->assertSame('', $response->text);
        $this->assertFalse($response->wasGenerated());
        $this->assertStringNotContainsString('This must not be echoed back.', $response->text);
    }
}
