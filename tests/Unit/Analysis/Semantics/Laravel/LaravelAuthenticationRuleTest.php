<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelAuthenticationRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelAuthenticationRuleTest extends TestCase
{
    public function testDetectsAuthFacadeAndRequestApis(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Http/Controllers/LoginController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class LoginController
{
    public function store(Request $request): void
    {
        Auth::attempt(['email' => 'a']);
        $request->user();
        Auth::guard('web');
    }

    public function helper(Request $request): void
    {
        $request->authenticate();
    }
}
PHP,
        ], [new LaravelAuthenticationRule()]);

        $this->assertSame(
            [
                ['App\\Http\\Controllers\\LoginController::helper', 'authentication'],
                ['App\\Http\\Controllers\\LoginController::store', 'authentication'],
            ],
            $annotations,
        );
    }

    public function testDoesNotClassifyArbitraryLoginLogoutOrAuthenticate(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Services/SessionService.php' => <<<'PHP'
<?php
namespace App\Services;
class SessionService
{
    public function login(): void {}
    public function logout(): void {}
    public function authenticate(): void
    {
        $this->login();
        $this->logout();
    }
}
PHP,
        ], [new LaravelAuthenticationRule()]);

        $this->assertSame([], $annotations);
    }
}
