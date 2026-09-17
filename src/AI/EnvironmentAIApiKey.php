<?php

declare(strict_types=1);

namespace Ripple\AI;

final class EnvironmentAIApiKey
{
    public const NAME = 'RIPPLE_AI_API_KEY';

    public static function read(): ?string
    {
        $value = getenv(self::NAME);
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
