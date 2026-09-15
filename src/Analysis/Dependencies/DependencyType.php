<?php

declare(strict_types=1);

namespace Ripple\Analysis\Dependencies;

enum DependencyType: string
{
    case MethodCall = 'method_call';
    case StaticCall = 'static_call';
    case ConstructorCall = 'constructor_call';
    case Extends = 'extends';
    case Implements = 'implements';
    case TraitUse = 'trait_use';
    case TypeReference = 'type_reference';
    case PropertyType = 'property_type';
    case ParameterType = 'parameter_type';
    case ReturnType = 'return_type';
}
