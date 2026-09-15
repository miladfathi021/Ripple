<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

enum TestImpactType: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';
}
