<?php

namespace Ripple\Tests\Fixtures\EnumExample;

enum ReservationStatus
{
    case Pending;
    case Accepted;

    public function label(): string
    {
        return $this->name;
    }
}
