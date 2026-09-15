<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelQueueRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelQueueRuleTest extends TestCase
{
    public function testAnnotatesShouldQueueHandleAndDispatch(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Jobs/SendPaymentEmail.php' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
class SendPaymentEmail implements ShouldQueue
{
    public function handle(): void {}
}
PHP,
            'app/Services/ReservationService.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SendPaymentEmail;
class ReservationService
{
    public function updateStatus(): void
    {
        SendPaymentEmail::dispatch();
    }
}
PHP,
        ], [new LaravelQueueRule()]);

        $this->assertContains(['App\\Jobs\\SendPaymentEmail::handle', 'queue'], $annotations);
        $this->assertContains(['App\\Services\\ReservationService::updateStatus', 'queue'], $annotations);
    }

    public function testDetectsBusFacadeDispatch(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Jobs/SendPaymentEmail.php' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
class SendPaymentEmail implements ShouldQueue
{
    public function handle(): void {}
}
PHP,
            'app/Services/ReservationService.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SendPaymentEmail;
use Illuminate\Support\Facades\Bus;
class ReservationService
{
    public function queueIt(): void
    {
        Bus::dispatch(new SendPaymentEmail());
    }
}
PHP,
        ], [new LaravelQueueRule()]);

        $this->assertContains(['App\\Services\\ReservationService::queueIt', 'queue'], $annotations);
    }

    public function testDoesNotClassifyJobNamesOrDispatchWithoutLaravelEvidence(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Jobs/SendEmailJob.php' => <<<'PHP'
<?php
namespace App\Jobs;
class SendEmailJob
{
    public function handle(): void {}
    public static function dispatch(): void {}
}
PHP,
            'app/Services/Mailer.php' => <<<'PHP'
<?php
namespace App\Services;
class Mailer
{
    public function dispatch(): void {}
    public function send(): void
    {
        $this->dispatch();
        SendEmailService::dispatch();
    }
}
class SendEmailService
{
    public static function dispatch(): void {}
}
PHP,
        ], [new LaravelQueueRule()]);

        $this->assertSame([], $annotations);
    }
}
