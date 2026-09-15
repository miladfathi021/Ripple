<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelEventRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelEventRuleTest extends TestCase
{
    public function testDetectsEventFacadeDispatchAndDispatchableEvents(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Events/ReservationUpdated.php' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Foundation\Events\Dispatchable;
class ReservationUpdated
{
    use Dispatchable;
}
PHP,
            'app/Services/ReservationService.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Events\ReservationUpdated;
use Illuminate\Support\Facades\Event;
class ReservationService
{
    public function updateStatus(): void
    {
        Event::dispatch(new ReservationUpdated());
    }
}
PHP,
        ], [new LaravelEventRule()]);

        $this->assertContains(['App\\Events\\ReservationUpdated', 'event'], $annotations);
        $this->assertContains(['App\\Services\\ReservationService::updateStatus', 'event'], $annotations);
    }

    public function testDetectsExplicitListenTargets(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Listeners/SendNotice.php' => <<<'PHP'
<?php
namespace App\Listeners;
class SendNotice
{
    public function handle(): void {}
}
PHP,
            'app/Providers/EventServiceProvider.php' => <<<'PHP'
<?php
namespace App\Providers;
use App\Listeners\SendNotice;
use Illuminate\Support\Facades\Event;
class EventServiceProvider
{
    public function boot(): void
    {
        Event::listen('reservation.updated', [SendNotice::class, 'handle']);
    }
}
PHP,
        ], [new LaravelEventRule()]);

        $this->assertContains(['App\\Listeners\\SendNotice::handle', 'event'], $annotations);
        $this->assertContains(['App\\Providers\\EventServiceProvider::boot', 'event'], $annotations);
    }

    public function testDoesNotClassifyEventOrListenerNamesAlone(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Events/SomethingEvent.php' => <<<'PHP'
<?php
namespace App\Events;
class SomethingEvent
{
    public function fire(): void {}
}
PHP,
            'app/Listeners/SomethingListener.php' => <<<'PHP'
<?php
namespace App\Listeners;
class SomethingListener
{
    public function handle(): void {}
}
PHP,
        ], [new LaravelEventRule()]);

        $this->assertSame([], $annotations);
    }
}
