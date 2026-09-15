<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelControllerRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelControllerRuleTest extends TestCase
{
    public function testAnnotatesPublicActionsOnLaravelControllerSubclasses(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Http/Controllers/Controller.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class Controller extends \Illuminate\Routing\Controller {}
PHP,
            'app/Http/Controllers/ReservationController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class ReservationController extends Controller
{
    public function update(): void {}
    protected function helper(): void {}
}
PHP,
        ], [new LaravelControllerRule()]);

        $this->assertContains(['App\\Http\\Controllers\\ReservationController::update', 'api_entrypoint'], $annotations);
        $this->assertNotContains(['App\\Http\\Controllers\\ReservationController::helper', 'api_entrypoint'], $annotations);
        $this->assertNotContains(['App\\Http\\Controllers\\Controller', 'api_entrypoint'], $annotations);
    }

    public function testAnnotatesExplicitRouteControllerActions(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Http/Controllers/ReservationController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class ReservationController
{
    public function update(): void {}
}
PHP,
            'routes/web.php' => <<<'PHP'
<?php
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;
Route::get('/reservations', [ReservationController::class, 'update']);
PHP,
        ], [new LaravelControllerRule()]);

        $this->assertContains(['App\\Http\\Controllers\\ReservationController::update', 'api_entrypoint'], $annotations);
    }

    public function testResourceRouteAnnotatesTheControllerClassNotInventedMethods(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Http/Controllers/ReservationController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class ReservationController
{
    public function update(): void {}
}
PHP,
            'routes/web.php' => <<<'PHP'
<?php
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;
Route::resource('reservations', ReservationController::class);
PHP,
        ], [new LaravelControllerRule()]);

        $this->assertContains(['App\\Http\\Controllers\\ReservationController', 'api_entrypoint'], $annotations);
        $this->assertNotContains(['App\\Http\\Controllers\\ReservationController::update', 'api_entrypoint'], $annotations);
    }

    public function testDoesNotClassifyControllersByNamespaceOrName(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Http/Controllers/UserController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class UserController
{
    public function show(): void {}
}
PHP,
            'src/PaymentController.php' => <<<'PHP'
<?php
class PaymentController
{
    public function pay(): void {}
}
PHP,
        ], [new LaravelControllerRule()]);

        $this->assertSame([], $annotations);
    }
}
