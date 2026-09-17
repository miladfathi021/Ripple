<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIResponse;

final class AIResponseTest extends TestCase
{
    public function testConstructsGeneratedText(): void
    {
        $response = new AIResponse('The change has a small blast radius.');

        $this->assertSame('The change has a small blast radius.', $response->text);
        $this->assertTrue($response->wasGenerated());
        $this->assertTrue($response->generated);
    }

    public function testNoneIsAnEmptyNonGeneratedResponse(): void
    {
        $response = AIResponse::none();

        $this->assertSame('', $response->text);
        $this->assertFalse($response->wasGenerated());
        $this->assertFalse($response->generated);
    }

    public function testGeneratedFactoryMarksTextAsGenerated(): void
    {
        $response = AIResponse::generated('This change may affect callers.');

        $this->assertSame('This change may affect callers.', $response->text);
        $this->assertTrue($response->wasGenerated());
    }
}
