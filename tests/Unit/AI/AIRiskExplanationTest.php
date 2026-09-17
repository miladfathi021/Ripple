<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ripple\AI\Explanation\AIRiskExplanation;

final class AIRiskExplanationTest extends TestCase
{
    public function testGeneratedResponseBecomesAnExplanation(): void
    {
        $explanation = new AIRiskExplanation(
            'Ripple rated this change High Risk with a score of 67.',
            true,
        );

        $this->assertTrue($explanation->wasGenerated());
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $explanation->text);
    }

    public function testNonGeneratedResponseProducesNoExplanation(): void
    {
        $explanation = AIRiskExplanation::none();

        $this->assertFalse($explanation->wasGenerated());
        $this->assertSame('', $explanation->text);
    }

    public function testWhitespaceGeneratedTextIsNotAnExplanation(): void
    {
        $explanation = new AIRiskExplanation("  \n", true);

        $this->assertFalse($explanation->wasGenerated());
    }

    public function testDoesNotStoreARiskScore(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(AIRiskExplanation::class))->getProperties(),
        );

        $this->assertSame(['text', 'generated'], $properties);
        $this->assertNotContains('score', $properties);
        $this->assertNotContains('level', $properties);
    }
}
