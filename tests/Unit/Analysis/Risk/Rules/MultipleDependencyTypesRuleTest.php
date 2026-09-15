<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk\Rules;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\Rules\MultipleDependencyTypesRule;
use Ripple\Tests\Unit\Analysis\Risk\RiskFactorTestHelpers;

final class MultipleDependencyTypesRuleTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testOneDependencyTypeEmitsNothing(): void
    {
        $factors = (new MultipleDependencyTypesRule())->evaluate($this->context(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::MethodCall, [11]),
            ],
        ));

        $this->assertSame([], $factors);
    }

    public function testTwoTypesIsInfo(): void
    {
        $factors = (new MultipleDependencyTypesRule())->evaluate($this->context(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::ParameterType),
            ],
        ));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES, $factors[0]->code);
        $this->assertSame(RiskSeverity::Info, $factors[0]->severity);
        $this->assertSame('Multiple dependency types', $factors[0]->title);
        $this->assertSame('A has dependents through 2 different dependency types.', $factors[0]->description);
        $this->assertSame(2, $factors[0]->value);
        $this->assertSame(
            [
                'symbol' => 'A',
                'dependency_types' => ['method_call', 'parameter_type'],
            ],
            $factors[0]->evidence,
        );
    }

    public function testThreeTypesIsAWarning(): void
    {
        $factors = (new MultipleDependencyTypesRule())->evaluate($this->context(
            ['ReservationService::updateStatus'],
            [
                $this->edge('B', 'ReservationService::updateStatus', DependencyType::MethodCall),
                $this->edge('C', 'ReservationService::updateStatus', DependencyType::ParameterType),
                $this->edge('D', 'ReservationService::updateStatus', DependencyType::StaticCall),
            ],
        ));

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame(3, $factors[0]->value);
        $this->assertSame(
            'ReservationService::updateStatus has dependents through 3 different dependency types.',
            $factors[0]->description,
        );
        $this->assertSame(
            ['method_call', 'parameter_type', 'static_call'],
            $factors[0]->evidence['dependency_types'],
        );
    }

    public function testDuplicateEdgesOfTheSameTypeCountOnce(): void
    {
        $factors = (new MultipleDependencyTypesRule())->evaluate($this->context(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall, [1]),
                $this->edge('C', 'A', DependencyType::MethodCall, [2]),
                $this->edge('D', 'A', DependencyType::MethodCall, [3]),
            ],
        ));

        $this->assertSame([], $factors);
    }

    public function testSelfEdgeTypeIsIgnored(): void
    {
        $factors = (new MultipleDependencyTypesRule())->evaluate($this->context(
            ['A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('A', 'A', DependencyType::ParameterType),
            ],
        ));

        $this->assertSame([], $factors);
    }

    public function testMultipleChangedSymbolsEmitOneFactorWithHighestSeverity(): void
    {
        $factors = (new MultipleDependencyTypesRule())->evaluate($this->context(
            ['Z', 'A'],
            [
                $this->edge('B', 'A', DependencyType::MethodCall),
                $this->edge('C', 'A', DependencyType::StaticCall),
                $this->edge('D', 'Z', DependencyType::MethodCall),
                $this->edge('E', 'Z', DependencyType::ParameterType),
                $this->edge('F', 'Z', DependencyType::ReturnType),
            ],
        ));

        $this->assertCount(1, $factors);
        $this->assertSame('Z', $factors[0]->evidence['symbol']);
        $this->assertSame(3, $factors[0]->value);
        $this->assertSame(RiskSeverity::Warning, $factors[0]->severity);
        $this->assertSame(
            [
                ['symbol' => 'A', 'dependency_types' => ['method_call', 'static_call']],
                ['symbol' => 'Z', 'dependency_types' => ['method_call', 'parameter_type', 'return_type']],
            ],
            $factors[0]->evidence['symbols'],
        );
    }
}
