<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk\Rules;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\Rules\HighFanInRule;
use Ripple\Tests\Unit\Analysis\Risk\RiskFactorTestHelpers;

final class HighFanInRuleTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testZeroDependentsEmitsNothing(): void
    {
        $this->assertSame([], (new HighFanInRule())->evaluate($this->context(['A'])));
    }

    public function testFourDependentsEmitsNothing(): void
    {
        $this->assertSame([], (new HighFanInRule())->evaluate(
            $this->context(['A'], $this->dependents('A', 4)),
        ));
    }

    public function testFiveDependentsIsAWarning(): void
    {
        $factors = (new HighFanInRule())->evaluate($this->context(['A'], $this->dependents('A', 5)));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_HIGH_FAN_IN, $factors[0]->code);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame('High fan-in', $factors[0]->title);
        $this->assertSame('A has 5 direct dependents.', $factors[0]->description);
        $this->assertSame(5, $factors[0]->value);
        $this->assertSame(['symbol' => 'A', 'direct_dependents' => 5], $factors[0]->evidence);
    }

    public function testNineDependentsIsAWarning(): void
    {
        $factors = (new HighFanInRule())->evaluate($this->context(['A'], $this->dependents('A', 9)));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame(9, $factors[0]->value);
    }

    public function testTenDependentsIsHigh(): void
    {
        $factors = (new HighFanInRule())->evaluate($this->context(['A'], $this->dependents('A', 10)));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
        $this->assertSame(10, $factors[0]->value);
        $this->assertSame('A has 10 direct dependents.', $factors[0]->description);
    }

    public function testCountsUniqueDirectDependentsNotBlastRadius(): void
    {
        $edges = array_merge(
            $this->dependents('A', 5),
            [
                $this->edge('T1', 'D00'),
                $this->edge('T2', 'T1'),
            ],
        );
        $factors = (new HighFanInRule())->evaluate($this->context(['A'], $edges));

        $this->assertCount(1, $factors);
        $this->assertSame(5, $factors[0]->value);
        $this->assertSame(5, $factors[0]->evidence['direct_dependents']);
    }

    public function testSelfEdgeIsNotCountedAsADependent(): void
    {
        $edges = array_merge($this->dependents('A', 4), [$this->edge('A', 'A')]);

        $this->assertSame([], (new HighFanInRule())->evaluate($this->context(['A'], $edges)));
    }

    public function testOnlyChangedSymbolsWithHighFanInEmitFactors(): void
    {
        $edges = array_merge($this->dependents('A', 10), $this->dependents('B', 2));
        $factors = (new HighFanInRule())->evaluate($this->context(['A', 'B'], $edges));

        $this->assertCount(1, $factors);
        $this->assertSame('A', $factors[0]->evidence['symbol']);
        $this->assertSame(10, $factors[0]->value);
    }

    public function testMultipleHighFanInSymbolsEmitOneFactorWithHighestSeverity(): void
    {
        $edges = array_merge($this->dependents('Z', 5), $this->dependents('A', 10));
        $factors = (new HighFanInRule())->evaluate($this->context(['Z', 'A'], $edges));

        $this->assertCount(1, $factors);
        $this->assertSame('A', $factors[0]->evidence['symbol']);
        $this->assertSame(10, $factors[0]->value);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
        $this->assertSame(
            [
                ['symbol' => 'A', 'direct_dependents' => 10],
                ['symbol' => 'Z', 'direct_dependents' => 5],
            ],
            $factors[0]->evidence['symbols'],
        );
    }

    /**
     * @return list<\Ripple\Analysis\Graph\GraphEdge>
     */
    private function dependents(string $target, int $count): array
    {
        $edges = [];
        for ($i = 0; $i < $count; $i++) {
            $edges[] = $this->edge('D' . $target . sprintf('%02d', $i), $target, DependencyType::MethodCall);
        }

        return $edges;
    }
}
