<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

enum FlowSemanticType: string
{
    case ApiEntrypoint = 'api_entrypoint';
    case DatabaseRead = 'database_read';
    case DatabaseWrite = 'database_write';
    case Queue = 'queue';
    case Event = 'event';
    case Authentication = 'authentication';
    case ExternalIntegration = 'external_integration';

    public static function fromConfig(string $value): self
    {
        $type = self::tryFrom($value);
        if ($type instanceof self) {
            return $type;
        }

        throw new InvalidSemanticConfigurationException(
            'Invalid semantic type "' . $value . '". Expected one of: ' . implode(', ', self::values()) . '.',
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
