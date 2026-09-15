<?php

declare(strict_types=1);

namespace Ripple\Analysis\Risk;

use RuntimeException;

final class UnknownRiskFactorCodeException extends RuntimeException
{
    public function __construct(public readonly string $factorCode)
    {
        parent::__construct("Unknown risk factor code: {$factorCode}");
    }
}
