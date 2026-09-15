<?php

namespace Ripple\Tests\Fixtures\InterfaceExample;

interface PaymentGateway
{
    public function charge(): void;
}
