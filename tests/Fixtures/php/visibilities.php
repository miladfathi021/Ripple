<?php

namespace Ripple\Tests\Fixtures\Visibilities;

class VisibilityExample
{
    public function pub(): void
    {
    }

    protected function prot(): void
    {
    }

    private function priv(): void
    {
    }

    function implicit(): void
    {
    }
}
