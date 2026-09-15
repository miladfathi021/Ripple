<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Flow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Flow\FlowType;

final class FlowTypeTest extends TestCase
{
    public function testEveryDependencyTypeHasAMapping(): void
    {
        $this->assertCount(count(DependencyType::cases()), self::dependencyTypeMappings());
    }

    #[DataProvider('dependencyTypeMappings')]
    public function testMapsEveryDependencyType(DependencyType $dependencyType, FlowType $expected): void
    {
        $this->assertSame($expected, FlowType::fromDependencyType($dependencyType));
    }

    /**
     * @return array<string, array{DependencyType, FlowType}>
     */
    public static function dependencyTypeMappings(): array
    {
        return [
            'method_call' => [DependencyType::MethodCall, FlowType::CallChain],
            'static_call' => [DependencyType::StaticCall, FlowType::CallChain],
            'constructor_call' => [DependencyType::ConstructorCall, FlowType::ConstructionChain],
            'extends' => [DependencyType::Extends, FlowType::InheritanceChain],
            'implements' => [DependencyType::Implements, FlowType::ContractChain],
            'trait_use' => [DependencyType::TraitUse, FlowType::ContractChain],
            'parameter_type' => [DependencyType::ParameterType, FlowType::TypeDependencyChain],
            'return_type' => [DependencyType::ReturnType, FlowType::TypeDependencyChain],
            'property_type' => [DependencyType::PropertyType, FlowType::TypeDependencyChain],
            'type_reference' => [DependencyType::TypeReference, FlowType::TypeDependencyChain],
        ];
    }
}
