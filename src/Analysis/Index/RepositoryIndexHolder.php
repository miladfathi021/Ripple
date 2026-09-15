<?php

declare(strict_types=1);

namespace Ripple\Analysis\Index;

final class RepositoryIndexHolder
{
    private ?RepositoryIndex $index = null;

    public function set(RepositoryIndex $index): void
    {
        $this->index = $index;
    }

    public function get(): ?RepositoryIndex
    {
        return $this->index;
    }
}
