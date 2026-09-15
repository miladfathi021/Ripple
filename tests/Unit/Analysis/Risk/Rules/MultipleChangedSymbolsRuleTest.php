<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk\Rules;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\Rules\MultipleChangedSymbolsRule;
use Ripple\Tests\Unit\Analysis\Risk\RiskFactorTestHelpers;

final class MultipleChangedSymbolsRuleTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testZeroChangedSymbolsEmitsNothing(): void
    {
        $this->assertSame([], (new MultipleChangedSymbolsRule())->evaluate($this->context()));
    }

    public function testOneChangedSymbolEmitsNothing(): void
    {
        $this->assertSame([], (new MultipleChangedSymbolsRule())->evaluate($this->context(['A'])));
    }

    public function testTwoChangedSymbolsIsInfo(): void
    {
        $factors = (new MultipleChangedSymbolsRule())->evaluate($this->context(['A', 'B']));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS, $factors[0]->code);
        $this->assertSame(RiskSeverity::Info, $factors[0]->severity);
        $this->assertSame('Multiple changed symbols', $factors[0]->title);
        $this->assertSame('2 symbols were changed in this analysis.', $factors[0]->description);
        $this->assertSame(2, $factors[0]->value);
        $this->assertSame(['changed_symbols' => 2], $factors[0]->evidence);
    }

    public function testThreeChangedSymbolsIsInfo(): void
    {
        $factors = (new MultipleChangedSymbolsRule())->evaluate($this->context(['A', 'B', 'C']));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::Info, $factors[0]->severity);
        $this->assertSame(3, $factors[0]->value);
    }

    public function testFourChangedSymbolsIsAWarning(): void
    {
        $factors = (new MultipleChangedSymbolsRule())->evaluate($this->context(['A', 'B', 'C', 'D']));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame(4, $factors[0]->value);
        $this->assertSame('4 symbols were changed in this analysis.', $factors[0]->description);
        $this->assertSame(['changed_symbols' => 4], $factors[0]->evidence);
    }

    public function testDuplicateChangedSymbolIdsAreCountedOnce(): void
    {
        $factors = (new MultipleChangedSymbolsRule())->evaluate($this->context(['A', 'A', 'B']));

        $this->assertCount(1, $factors);
        $this->assertSame(2, $factors[0]->value);
    }
}
