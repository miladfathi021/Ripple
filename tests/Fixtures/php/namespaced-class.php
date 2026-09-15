<?php

namespace Ripple\Tests\Fixtures\NamespacedClass;

use App\Models\Reservation;

class ReservationService
{
    public function updateStatus(): void
    {
    }

    protected function validate(): bool
    {
        return true;
    }

    private static function normalize(): string
    {
        return '';
    }
}
