<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\Laravel\Rules\LaravelDatabaseReadRule;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelDatabaseReadRuleTest extends TestCase
{
    public function testDetectsEloquentReads(): void
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
    public function findPayment(): mixed
    {
        return Payment::find(1);
    }
}
PHP,
        ], [new LaravelDatabaseReadRule()]);

        $this->assertSame(
            [['App\\Repositories\\PaymentRepository::findPayment', 'database_read']],
            $annotations,
        );
    }

    public function testDetectsQueryBuilderAndDbFacadeReads(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Repositories/PaymentRepository.php' => <<<'PHP'
<?php
namespace App\Repositories;
use Illuminate\Support\Facades\DB;
class PaymentRepository
{
    public function allRows(): mixed
    {
        return DB::table('payments')->get();
    }

    public function sql(): mixed
    {
        return DB::select('select 1');
    }
}
PHP,
        ], [new LaravelDatabaseReadRule()]);

        $this->assertSame(
            [
                ['App\\Repositories\\PaymentRepository::allRows', 'database_read'],
                ['App\\Repositories\\PaymentRepository::sql', 'database_read'],
            ],
            $annotations,
        );
    }

    public function testDoesNotClassifyArbitraryFindOrGetMethods(): void
    {
        $annotations = IndexedPhp::annotations([
            'app/Services/Lookup.php' => <<<'PHP'
<?php
namespace App\Services;
class Gateway
{
    public function find(): void {}
    public function get(): void {}
}
class Lookup
{
    public function findUser(Gateway $gateway): void
    {
        $gateway->find();
        $this->getPayment();
    }

    public function getPayment(): void
    {
        $this->get();
    }

    public function get(): void {}
}
PHP,
        ], [new LaravelDatabaseReadRule()]);

        $this->assertSame([], $annotations);
    }
}
