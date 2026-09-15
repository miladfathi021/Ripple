<?php

declare(strict_types=1);

namespace Ripple\Git;

use RuntimeException;

final class NotAGitRepository extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('not a Git repository.');
    }
}
