<?php

namespace Ripple\Tests\Fixtures\PhpunitTestAttribute;

use PHPUnit\Framework\Attributes\Test;

class AttributeExample
{
    #[Test]
    public function updates_status(): void
    {
    }

    public function helper(): void
    {
    }
}
