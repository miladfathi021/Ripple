<?php

namespace Ripple\Tests\Fixtures\AnonymousClass;

class Named
{
    public function make(): object
    {
        return new class {
            public function run(): void
            {
            }
        };
    }
}
