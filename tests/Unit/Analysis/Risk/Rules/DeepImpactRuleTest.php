<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk\Rules;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\Rules\DeepImpactRule;
use Ripple\Tests\Unit\Analysis\Risk\RiskFactorTestHelpers;

final class DeepImpactRuleTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testNoBlastRadiusEmitsNothing(): void
    {
        $this->assertSame([], (new DeepImpactRule())->evaluate($this->context()));
    }

    public function testDepthOneEmitsNothing(): void
    {
        $this->assertSame([], (new DeepImpactRule())->evaluate(
            $this->context(idToDepth: ['B' => 1]),
        ));
    }

    public function testDepthTwoIsAWarning(): void
    {
        $factors = (new DeepImpactRule())->evaluate($this->context(idToDepth: ['B' => 1, 'C' => 2]));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_DEEP_IMPACT, $factors[0]->code);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame('Deep dependency chain', $factors[0]->title);
        $this->assertSame(
            'The change can reach impacted symbols up to 2 dependency levels away.',
            $factors[0]->description,
        );
        $this->assertSame(2, $factors[0]->value);
        $this->assertSame(['max_depth' => 2], $factors[0]->evidence);
    }

    public function testDepthThreeIsAWarning(): void
    {
        $factors = (new DeepImpactRule())->evaluate($this->context(idToDepth: ['B' => 1, 'C' => 3]));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame(3, $factors[0]->value);
    }

    public function testDepthFourIsHigh(): void
    {
        $factors = (new DeepImpactRule())->evaluate($this->context(idToDepth: ['B' => 1, 'E' => 4]));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
        $this->assertSame(4, $factors[0]->value);
        $this->assertSame(['max_depth' => 4], $factors[0]->evidence);
    }

    public function testUsesTheMaximumAmongMultipleDepths(): void
    {
        $factors = (new DeepImpactRule())->evaluate($this->context(idToDepth: [
            'B' => 1,
            'C' => 2,
            'D' => 4,
            'E' => 3,
        ]));

        $this->assertCount(1, $factors);
        $this->assertSame(4, $factors[0]->value);
        $this->assertSame(['max_depth' => 4], $factors[0]->evidence);
    }
}
