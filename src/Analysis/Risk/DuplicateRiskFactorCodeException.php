<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

use RuntimeException;

final class DuplicateRiskFactorCodeException extends RuntimeException
{
    public function __construct(public readonly string $factorCode)
    {
        parent::__construct("Duplicate risk factor code: {$factorCode}");
    }
}
