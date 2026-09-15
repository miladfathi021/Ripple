<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelDatabaseWriteRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelDatabaseWriteRuleTest extends TestCase
{
    public function testDetectsEloquentWrites(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Models/Payment.php' => <<<'PHP'
<?php
namespace App\Models;
class Payment extends \Illuminate\Database\Eloquent\Model {}
PHP,
            'app/Repositories/PaymentRepository.php' => <<<'PHP'
<?php
namespace App\Repositories;
use App\Models\Payment;
class PaymentRepository
{
    public function update(Payment $model): void
    {
        $model->update(['status' => 'paid']);
    }

    public function store(): void
    {
        Payment::create(['amount' => 1]);
    }

    public function persist(Payment $model): void
    {
        $model->save();
    }

    public function remove(Payment $model): void
    {
        $model->delete();
    }
}
PHP,
        ], [new LaravelDatabaseWriteRule()]);

        $this->assertSame(
            [
                ['App\\Repositories\\PaymentRepository::persist', 'database_write'],
                ['App\\Repositories\\PaymentRepository::remove', 'database_write'],
                ['App\\Repositories\\PaymentRepository::store', 'database_write'],
                ['App\\Repositories\\PaymentRepository::update', 'database_write'],
            ],
            $annotations,
        );
    }

    public function testDetectsQueryBuilderInsertUpdateDelete(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Repositories/PaymentRepository.php' => <<<'PHP'
<?php
namespace App\Repositories;
use Illuminate\Support\Facades\DB;
class PaymentRepository
{
    public function write(): void
    {
        DB::table('payments')->insert(['amount' => 1]);
        DB::table('payments')->update(['amount' => 2]);
        DB::table('payments')->delete();
    }
}
PHP,
        ], [new LaravelDatabaseWriteRule()]);

        $this->assertSame(
            [['App\\Repositories\\PaymentRepository::write', 'database_write']],
            $annotations,
        );
    }

    public function testDoesNotClassifyArbitraryUpdateSaveOrDeleteNames(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Services/Gateway.php' => <<<'PHP'
<?php
namespace App\Services;
class PaymentGateway
{
    public function updatePayment(): void {}
    public function saveSomething(): void {}
    public function deleteSomething(): void {}
}
class BillingService
{
    public function updatePayment(PaymentGateway $gateway): void
    {
        $gateway->updatePayment();
        $this->saveReservation();
        $this->deleteSomething();
    }

    public function saveReservation(): void {}
    public function deleteSomething(): void {}
}
PHP,
        ], [new LaravelDatabaseWriteRule()]);

        $this->assertSame([], $annotations);
    }
}
