<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Dependencies;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\AstAnalyzer;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyExtractor;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Dependencies\DependencyType;

final class DependencyExtractorTest extends TestCase
{
    public function testExtractsATypedMethodCall(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(PaymentService $paymentService): void
    {
        $paymentService->validate();
    }
}
PHP);

        $dependency = $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService::validate', DependencyType::MethodCall);
        $this->assertSame(DependencyType::MethodCall, $dependency->type);
        $this->assertSame(1, $dependency->occurrences);
        $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService', DependencyType::ParameterType);
    }

    public function testExtractsAStaticCall(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(): void
    {
        PaymentService::validate();
    }
}
PHP);

        $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService::validate', DependencyType::StaticCall);
    }

    public function testExtractsAConstructorCall(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(): void
    {
        $payment = new PaymentService();
    }
}
PHP);

        $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService', DependencyType::ConstructorCall);
    }

    public function testExtractsExtends(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ChildService extends BaseService
{
}
PHP);

        $this->mustFind($result, 'ChildService', 'BaseService', DependencyType::Extends);
    }

    public function testExtractsImplements(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class PaymentService implements PaymentContract
{
}
PHP);

        $this->mustFind($result, 'PaymentService', 'PaymentContract', DependencyType::Implements);
    }

    public function testExtractsTraitUse(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    use LogsActivity;
}
PHP);

        $this->mustFind($result, 'ReservationService', 'LogsActivity', DependencyType::TraitUse);
    }

    public function testExtractsAParameterType(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function update(PaymentService $payment): void
    {
    }
}
PHP);

        $this->mustFind($result, 'ReservationService::update', 'PaymentService', DependencyType::ParameterType);
    }

    public function testExtractsAReturnType(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function update(): Reservation
    {
        return new Reservation();
    }
}
PHP);

        $this->mustFind($result, 'ReservationService::update', 'Reservation', DependencyType::ReturnType);
    }

    public function testExtractsAPropertyType(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    private PaymentService $paymentService;
}
PHP);

        $this->mustFind($result, 'ReservationService', 'PaymentService', DependencyType::PropertyType);
    }

    public function testResolvesImportedNamesToFullyQualifiedTargets(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
namespace App\Services;

use App\Payments\PaymentService;

class ReservationService
{
    public function create(): void
    {
        $service = new PaymentService();
    }
}
PHP);

        $this->mustFind(
            $result,
            'App\\Services\\ReservationService::create',
            'App\\Payments\\PaymentService',
            DependencyType::ConstructorCall,
        );
        $this->assertNull(
            $this->find($result, 'App\\Services\\ReservationService::create', 'PaymentService', DependencyType::ConstructorCall),
        );
    }

    public function testOneMethodCanProduceMultipleDependencies(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(PaymentService $payment): Reservation
    {
        $payment->validate();
        return new Reservation();
    }
}
PHP);

        $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService', DependencyType::ParameterType);
        $this->mustFind($result, 'ReservationService::updateStatus', 'Reservation', DependencyType::ReturnType);
        $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService::validate', DependencyType::MethodCall);
        $this->mustFind($result, 'ReservationService::updateStatus', 'Reservation', DependencyType::ConstructorCall);
    }

    public function testDeduplicatesRepeatedIdenticalCalls(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(PaymentService $payment): void
    {
        $payment->validate();
        $payment->validate();
        $payment->validate();
    }
}
PHP);

        $dependency = $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService::validate', DependencyType::MethodCall);
        $this->assertSame(3, $dependency->occurrences);
        $this->assertCount(1, array_filter(
            $result->dependencies,
            static fn (Dependency $item): bool => $item->target === 'PaymentService::validate' && $item->type === DependencyType::MethodCall,
        ));
        $this->assertSame(3, count($dependency->lines));
    }

    public function testDoesNotInventTargetsForUnresolvableCalls(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    public function updateStatus($service, string $methodName): void
    {
        $service->$methodName();
        $unknown->validate();
    }
}
PHP);

        $methodCalls = array_values(array_filter(
            $result->dependencies,
            static fn (Dependency $dependency): bool => $dependency->type === DependencyType::MethodCall,
        ));

        $this->assertSame([], $methodCalls);
        foreach ($result->dependencies as $dependency) {
            $this->assertStringNotContainsString('validate', $dependency->target);
        }
    }

    public function testAssignsDependenciesToTheCorrectClassInAMultiClassFile(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class Alpha
{
    public function run(): void
    {
        new Beta();
    }
}

class Beta
{
}
PHP);

        $this->mustFind($result, 'Alpha::run', 'Beta', DependencyType::ConstructorCall);
        foreach ($result->dependencies as $dependency) {
            $this->assertSame('Alpha::run', $dependency->source);
        }
    }

    public function testExtractsDependenciesFromInterfaceTraitAndEnumSymbols(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
interface PaymentGateway
{
    public function charge(PaymentService $payment): Receipt;
}

trait LogsActivity
{
    public function log(Logger $logger): void
    {
        $logger->info();
    }
}

enum ReservationStatus implements Stringable
{
    public function label(): string
    {
        return StatusFormatter::format();
    }
}
PHP);

        $this->mustFind($result, 'PaymentGateway::charge', 'PaymentService', DependencyType::ParameterType);
        $this->mustFind($result, 'PaymentGateway::charge', 'Receipt', DependencyType::ReturnType);
        $this->mustFind($result, 'LogsActivity::log', 'Logger', DependencyType::ParameterType);
        $this->mustFind($result, 'LogsActivity::log', 'Logger::info', DependencyType::MethodCall);
        $this->mustFind($result, 'ReservationStatus', 'Stringable', DependencyType::Implements);
        $this->mustFind($result, 'ReservationStatus::label', 'StatusFormatter::format', DependencyType::StaticCall);
    }

    public function testResolvesThisAndTypedPropertyMethodCalls(): void
    {
        $result = $this->extract(<<<'PHP'
<?php
class ReservationService
{
    private PaymentService $paymentService;

    public function updateStatus(): void
    {
        $this->notify();
        $this->paymentService->validate();
    }

    private function notify(): void
    {
    }
}
PHP);

        $this->mustFind($result, 'ReservationService::updateStatus', 'ReservationService::notify', DependencyType::MethodCall);
        $this->mustFind($result, 'ReservationService::updateStatus', 'PaymentService::validate', DependencyType::MethodCall);
    }

    private function extract(string $source): DependencyResult
    {
        $parsed = (new AstAnalyzer())->parse($source, 'src/Example.php');

        return (new DependencyExtractor())->extract($parsed);
    }

    private function mustFind(
        DependencyResult $result,
        string $source,
        string $target,
        DependencyType $type,
    ): Dependency {
        $dependency = $this->find($result, $source, $target, $type);
        $this->assertNotNull($dependency, sprintf('Missing %s %s → %s', $type->value, $source, $target));

        return $dependency;
    }

    private function find(
        DependencyResult $result,
        string $source,
        string $target,
        DependencyType $type,
    ): ?Dependency {
        foreach ($result->dependencies as $dependency) {
            if (
                $dependency->source === $source
                && $dependency->target === $target
                && $dependency->type === $type
            ) {
                return $dependency;
            }
        }

        return null;
    }
}
