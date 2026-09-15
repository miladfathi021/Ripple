<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

enum SymbolType: string
{
    case Class_ = 'class';
    case Interface = 'interface';
    case Trait = 'trait';
    case Enum = 'enum';
    case Method = 'method';
    case Function = 'function';
}
