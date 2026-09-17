<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderException;
use Ripple\AI\AIRequest;

final class AIRequestTest extends TestCase
{
    public function testConstructsAProviderNeutralRequest(): void
    {
        $request = new AIRequest(
            input: 'Summarize this analysis.',
            instructions: 'Write a short explanation.',
        );

        $this->assertSame('Summarize this analysis.', $request->input);
        $this->assertSame('Write a short explanation.', $request->instructions);
    }

    public function testInstructionsDefaultToEmpty(): void
    {
        $request = new AIRequest('Explain the blast radius.');

        $this->assertSame('Explain the blast radius.', $request->input);
        $this->assertSame('', $request->instructions);
    }

    public function testEmptyInputIsRejected(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('AI request input must be a non-empty string.');

        new AIRequest('');
    }

    public function testWhitespaceOnlyInputIsRejected(): void
    {
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('AI request input must be a non-empty string.');

        new AIRequest("  \n\t");
    }
}
