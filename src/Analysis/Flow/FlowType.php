<?php

declare(strict_types=1);

namespace Ripple\Analysis\Flow;

use Ripple\Analysis\Dependencies\DependencyType;

enum FlowType: string
{
    case CallChain = 'call_chain';
    case ConstructionChain = 'construction_chain';
    case InheritanceChain = 'inheritance_chain';
    case ContractChain = 'contract_chain';
    case TypeDependencyChain = 'type_dependency_chain';

    public static function fromDependencyType(DependencyType $type): self
    {
        return match ($type) {
            DependencyType::MethodCall, DependencyType::StaticCall => self::CallChain,
            DependencyType::ConstructorCall => self::ConstructionChain,
            DependencyType::Extends => self::InheritanceChain,
            DependencyType::Implements, DependencyType::TraitUse => self::ContractChain,
            DependencyType::ParameterType,
            DependencyType::ReturnType,
            DependencyType::PropertyType,
            DependencyType::TypeReference => self::TypeDependencyChain,
        };
    }
}
