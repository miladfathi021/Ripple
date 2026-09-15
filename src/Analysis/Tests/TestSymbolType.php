<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

enum TestSymbolType: string
{
    case Class_ = 'class';
    case Method = 'method';
}
