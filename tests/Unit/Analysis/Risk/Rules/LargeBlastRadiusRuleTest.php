<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk\Rules;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorContext;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\Rules\LargeBlastRadiusRule;
use Ripple\Tests\Unit\Analysis\Risk\RiskFactorTestHelpers;

final class LargeBlastRadiusRuleTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testZeroImpactedSymbolsEmitsNothing(): void
    {
        $this->assertSame([], (new LargeBlastRadiusRule())->evaluate($this->context()));
    }

    public function testFourImpactedSymbolsEmitsNothing(): void
    {
        $this->assertSame([], (new LargeBlastRadiusRule())->evaluate(
            $this->context(idToDepth: $this->symbols(4)),
        ));
    }

    public function testFiveImpactedSymbolsIsAWarning(): void
    {
        $factors = (new LargeBlastRadiusRule())->evaluate($this->context(idToDepth: $this->symbols(5)));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_LARGE_BLAST_RADIUS, $factors[0]->code);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame('Large blast radius', $factors[0]->title);
        $this->assertSame('5 symbols may be affected by this change.', $factors[0]->description);
        $this->assertSame(5, $factors[0]->value);
        $this->assertSame(['affected_symbols' => 5], $factors[0]->evidence);
    }

    public function testNineImpactedSymbolsIsAWarning(): void
    {
        $factors = (new LargeBlastRadiusRule())->evaluate($this->context(idToDepth: $this->symbols(9)));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame(9, $factors[0]->value);
    }

    public function testTenImpactedSymbolsIsHigh(): void
    {
        $factors = (new LargeBlastRadiusRule())->evaluate($this->context(idToDepth: $this->symbols(10)));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
        $this->assertSame(10, $factors[0]->value);
        $this->assertSame('10 symbols may be affected by this change.', $factors[0]->description);
    }

    public function testDuplicateImpactedSymbolsAreCountedOnce(): void
    {
        $blast = new TransitiveImpactResult([
            new BlastRadiusEntry('B', 1, []),
            new BlastRadiusEntry('B', 2, []),
            new BlastRadiusEntry('C', 1, []),
            new BlastRadiusEntry('D', 1, []),
            new BlastRadiusEntry('E', 1, []),
            new BlastRadiusEntry('F', 1, []),
        ]);
        $factors = (new LargeBlastRadiusRule())->evaluate(new RiskFactorContext(
            $this->changedSymbols(['A']),
            $this->reverseGraph([]),
            $blast,
        ));

        $this->assertCount(1, $factors);
        $this->assertSame(5, $factors[0]->value);
        $this->assertSame(['affected_symbols' => 5], $factors[0]->evidence);
    }

    /**
     * @return array<string, int>
     */
    private function symbols(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids['S' . sprintf('%02d', $i)] = 1;
        }

        return $ids;
    }
}
