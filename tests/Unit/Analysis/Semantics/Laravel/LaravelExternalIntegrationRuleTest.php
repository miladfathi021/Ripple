<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelExternalIntegrationRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelExternalIntegrationRuleTest extends TestCase
{
    public function testDetectsLaravelHttpFacade(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Clients/BillingClient.php' => <<<'PHP'
<?php
namespace App\Clients;
use Illuminate\Support\Facades\Http;
class BillingClient
{
    public function charge(): void
    {
        Http::post('https://example.test', ['amount' => 1]);
    }
}
PHP,
        ], [new LaravelExternalIntegrationRule()]);

        $this->assertSame(
            [['App\\Clients\\BillingClient::charge', 'external_integration']],
            $annotations,
        );
    }

    public function testDetectsGuzzleClientUsage(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Clients/BillingClient.php' => <<<'PHP'
<?php
namespace App\Clients;
use GuzzleHttp\Client;
class BillingClient
{
    public function charge(Client $client): void
    {
        $client->post('https://example.test');
    }

    public function build(): Client
    {
        return new Client();
    }
}
PHP,
        ], [new LaravelExternalIntegrationRule()]);

        $this->assertSame(
            [
                ['App\\Clients\\BillingClient::build', 'external_integration'],
                ['App\\Clients\\BillingClient::charge', 'external_integration'],
            ],
            $annotations,
        );
    }

    public function testDoesNotClassifyClientsWithoutHttpUsage(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Clients/StripeClient.php' => <<<'PHP'
<?php
namespace App\Clients;
class StripeClient
{
    public function charge(): void {}
}
class ApiService
{
    public function call(StripeClient $client): void
    {
        $client->charge();
    }
}
class PaymentClient
{
    public function send(): void {}
}
PHP,
        ], [new LaravelExternalIntegrationRule()]);

        $this->assertSame([], $annotations);
    }
}
