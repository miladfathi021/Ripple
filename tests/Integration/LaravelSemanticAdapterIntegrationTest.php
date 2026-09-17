<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\CLI\ApplicationFactory;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Reporting\ReportFormatterFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LaravelSemanticAdapterIntegrationTest extends TestCase
{
    public function testLaravelModeClassifiesExplicitFrameworkApisNotNames(): void
    {
        $path = $this->fixtureRepository($this->laravelConfig());
        $result = ApplicationFactory::runnerForWorkingDirectory($path)->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(
            'App\\Services\\ReservationService::updateStatus',
            $result->changedSymbols?->changedSymbols[0]->symbol->fullyQualifiedName,
        );
        $this->assertNotNull($result->semanticImpact);

        $blast = array_map(
            static fn ($annotation): array => [$annotation->symbolId, $annotation->type->value],
            $result->semanticImpact->blastRadiusAnnotations,
        );
        $flows = array_map(
            static fn ($annotation): array => [$annotation->symbolId, $annotation->type->value],
            $result->semanticImpact->affectedFlowAnnotations,
        );

        $this->assertContains(['App\\Http\\Controllers\\ReservationController::update', 'api_entrypoint'], $blast);
        $this->assertContains(['App\\Repositories\\PaymentRepository::update', 'database_write'], $flows);
        $this->assertContains(['App\\Jobs\\SendPaymentEmail::dispatch', 'queue'], $flows);
        $this->assertContains(['App\\Clients\\StripeGateway::charge', 'external_integration'], $flows);

        $controllerBase = $result->graph?->getNode('Illuminate\\Routing\\Controller');
        if ($controllerBase !== null) {
            $this->assertFalse($controllerBase->known);
        }
        $modelBase = $result->graph?->getNode('Illuminate\\Database\\Eloquent\\Model');
        if ($modelBase !== null) {
            $this->assertFalse($modelBase->known);
        }
    }

    public function testWithoutLaravelModeNamesAndLaravelApisAreNotClassified(): void
    {
        $path = $this->fixtureRepository('{"semantic_annotations": []}');
        $result = ApplicationFactory::runnerForWorkingDirectory($path)->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->semanticImpact);
        $this->assertTrue($result->semanticImpact->isEmpty());
        $this->assertNotSame([], $result->blastRadius?->getImpactedSymbolIds() ?? []);
    }

    public function testExplicitJsonAnnotationsAreCombinedWithLaravelFacts(): void
    {
        $path = $this->fixtureRepository(<<<'JSON'
{
  "framework": "laravel",
  "semantic_annotations": [
    {"symbol": "App\\Services\\ReservationService::updateStatus", "type": "authentication"}
  ]
}
JSON);
        $result = ApplicationFactory::runnerForWorkingDirectory($path)->run();
        $this->assertTrue($result->isSuccessful());

        $flowTypes = [];
        foreach ($result->semanticImpact?->affectedFlowAnnotations ?? [] as $annotation) {
            $flowTypes[$annotation->symbolId][] = $annotation->type->value;
        }
        $this->assertContains('database_write', $flowTypes['App\\Repositories\\PaymentRepository::update'] ?? []);
    }

    public function testCliReportsProviderIndependentSemanticImpact(): void
    {
        $tester = new CommandTester(new AnalyzeCommand(
            ApplicationFactory::runnerForWorkingDirectory($this->fixtureRepository($this->laravelConfig())),
            new ReportFormatterFactory(),
        ));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $text = $tester->getDisplay();
        $this->assertStringContainsString('Ripple Analysis', $text);
        $this->assertStringNotContainsString('[api_entrypoint]', $text);
        $this->assertStringNotContainsString('laravel', strtolower($text));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('blast_radius', $payload['semantic_impact']);
        $this->assertArrayHasKey('affected_flows', $payload['semantic_impact']);
        $this->assertNotSame([], $payload['semantic_impact']['blast_radius']);
        $this->assertNotSame([], $payload['semantic_impact']['affected_flows']);
    }

    private function laravelConfig(): string
    {
        return '{"framework": "laravel"}';
    }

    private function fixtureRepository(string $config): string
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->files() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write(
            'app/Services/ReservationService.php',
            str_replace('$ok = true;', '$ok = false;', $this->files()['app/Services/ReservationService.php']),
        );
        $repository->write('ripple.json', $config);

        return $repository->path;
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        return [
            'app/Http/Controllers/Controller.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class Controller extends \Illuminate\Routing\Controller {}
PHP,
            'app/Http/Controllers/ReservationController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Services\ReservationService;
class ReservationController extends Controller
{
    public function update(ReservationService $service): void
    {
        $service->updateStatus();
    }
}
PHP,
            'app/Services/ReservationService.php' => <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SendPaymentEmail;
use App\Payments\PaymentService;
class ReservationService
{
    public function updateStatus(PaymentService $payment): void
    {
        $ok = true;
        $payment->validate();
        SendPaymentEmail::dispatch();
    }
}
PHP,
            'app/Payments/PaymentService.php' => <<<'PHP'
<?php
namespace App\Payments;
use App\Repositories\PaymentRepository;
class PaymentService
{
    public function validate(PaymentRepository $repository): void
    {
        $repository->update();
    }
}
PHP,
            'app/Models/Payment.php' => <<<'PHP'
<?php
namespace App\Models;
class Payment extends \Illuminate\Database\Eloquent\Model {}
PHP,
            'app/Repositories/PaymentRepository.php' => <<<'PHP'
<?php
namespace App\Repositories;
use App\Clients\StripeGateway;
use App\Models\Payment;
class PaymentRepository
{
    public function update(Payment $model, StripeGateway $gateway): void
    {
        $model->update(['status' => 'paid']);
        $gateway->charge();
    }
}
PHP,
            'app/Clients/StripeGateway.php' => <<<'PHP'
<?php
namespace App\Clients;
use Illuminate\Support\Facades\Http;
class StripeGateway
{
    public function charge(): void
    {
        Http::post('https://api.stripe.test/v1/charges', ['amount' => 1]);
    }
}
PHP,
            'app/Jobs/SendPaymentEmail.php' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
class SendPaymentEmail implements ShouldQueue
{
    public function handle(): void
    {
        $sent = true;
    }
}
PHP,
        ];
    }
}
