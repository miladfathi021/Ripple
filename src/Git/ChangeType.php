<?php

declare(strict_types=1);

namespace Ripple\Git;

enum ChangeType: string
{
    case Modified = 'modified';
    case Added = 'added';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}
