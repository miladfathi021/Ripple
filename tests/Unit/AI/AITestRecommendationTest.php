<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ripple\AI\Testing\AITestRecommendation;

final class AITestRecommendationTest extends TestCase
{
    public function testGeneratedRecommendationExposesText(): void
    {
        $recommendation = AITestRecommendation::generated(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
        );

        $this->assertTrue($recommendation->generated);
        $this->assertTrue($recommendation->wasGenerated());
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $recommendation->text(),
        );
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $recommendation->text,
        );
    }

    public function testNoneProducesNoRecommendation(): void
    {
        $recommendation = AITestRecommendation::none();

        $this->assertFalse($recommendation->generated);
        $this->assertFalse($recommendation->wasGenerated());
        $this->assertSame('', $recommendation->text());
    }

    public function testWhitespaceGeneratedTextIsNotARecommendation(): void
    {
        $recommendation = new AITestRecommendation("  \n", true);

        $this->assertFalse($recommendation->wasGenerated());
    }

    public function testDoesNotStoreTestImpactOrCoverage(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(AITestRecommendation::class))->getProperties(),
        );

        $this->assertSame(['text', 'generated'], $properties);
        $this->assertNotContains('score', $properties);
        $this->assertNotContains('coverage', $properties);
        $this->assertNotContains('impacts', $properties);
    }
}
