<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Tests;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Tests\TestCatalog;
use Ripple\Analysis\Tests\TestSymbol;
use Ripple\Analysis\Tests\TestSymbolDetector;
use Ripple\Analysis\Tests\TestSymbolType;
use Ripple\Tests\Support\IndexedPhp;

final class TestSymbolDetectorTest extends TestCase
{
    public function testDetectsDirectPhpUnitTestCaseInheritanceAndPrefixedMethods(): void
    {
        $catalog = $this->detect([
            'tests/Unit/ReservationServiceTest.php' => <<<'PHP'
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReservationServiceTest extends TestCase
{
    public function testUpdateStatus(): void
    {
    }

    public function helper(): void
    {
    }
}
PHP,
        ]);

        $this->assertTrue($catalog->isTestSymbol('Tests\\Unit\\ReservationServiceTest'));
        $this->assertTrue($catalog->isTestMethod('Tests\\Unit\\ReservationServiceTest::testUpdateStatus'));
        $this->assertFalse($catalog->isTestSymbol('Tests\\Unit\\ReservationServiceTest::helper'));
        $method = $catalog->getTestSymbol('Tests\\Unit\\ReservationServiceTest::testUpdateStatus');
        $this->assertNotNull($method);
        $this->assertSame(TestSymbolType::Method, $method->type);
        $this->assertSame('tests/Unit/ReservationServiceTest.php', $method->file);
        $this->assertSame('Tests\\Unit\\ReservationServiceTest', $method->testClassFqn);
        $this->assertSame('testUpdateStatus', $method->methodName);
    }

    public function testDetectsIndirectInheritanceThroughAProjectBaseClass(): void
    {
        $catalog = $this->detect([
            'tests/TestCase.php' => <<<'PHP'
<?php

namespace Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase
{
}
PHP,
            'tests/Unit/FooTest.php' => <<<'PHP'
<?php

namespace Tests\Unit;

use Tests\TestCase;

class FooTest extends TestCase
{
    public function testItWorks(): void
    {
    }
}
PHP,
        ]);

        $this->assertTrue($catalog->isTestSymbol('Tests\\TestCase'));
        $this->assertTrue($catalog->isTestSymbol('Tests\\Unit\\FooTest'));
        $this->assertTrue($catalog->isTestMethod('Tests\\Unit\\FooTest::testItWorks'));
    }

    public function testDetectsMultipleInheritanceLevels(): void
    {
        $catalog = $this->detect([
            'tests/BaseTest.php' => <<<'PHP'
<?php
class BaseTest extends PHPUnit\Framework\TestCase
{
}
PHP,
            'tests/MidTest.php' => <<<'PHP'
<?php
class MidTest extends BaseTest
{
}
PHP,
            'tests/LeafTest.php' => <<<'PHP'
<?php
class LeafTest extends MidTest
{
    public function testLeaf(): void
    {
    }
}
PHP,
        ]);

        $this->assertTrue($catalog->isTestMethod('LeafTest::testLeaf'));
        $this->assertTrue($catalog->isTestSymbol('MidTest'));
    }

    public function testDetectsPhpUnitTestAttribute(): void
    {
        $catalog = $this->detect([
            'tests/Unit/AttributeTest.php' => <<<'PHP'
<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttributeTest extends TestCase
{
    #[Test]
    public function updates_reservation_status(): void
    {
    }
}
PHP,
        ]);

        $this->assertTrue($catalog->isTestMethod('Tests\\Unit\\AttributeTest::updates_reservation_status'));
        $method = $catalog->getTestSymbol('Tests\\Unit\\AttributeTest::updates_reservation_status');
        $this->assertSame('updates_reservation_status', $method?->methodName);
    }

    public function testIgnoresNonTestMethodsAndNonTestClasses(): void
    {
        $catalog = $this->detect([
            'src/ReservationService.php' => <<<'PHP'
<?php
class ReservationService
{
    public function testNamedProductionMethod(): void
    {
    }
}
PHP,
            'tests/Unit/ReservationServiceTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
class ReservationServiceTest extends TestCase
{
    public function setUp(): void
    {
    }

    protected function testProtectedStillMatchesPrefix(): void
    {
    }
}
PHP,
        ]);

        $this->assertFalse($catalog->isTestSymbol('ReservationService'));
        $this->assertFalse($catalog->isTestSymbol('ReservationService::testNamedProductionMethod'));
        $this->assertFalse($catalog->isTestSymbol('ReservationServiceTest::setUp'));
        $this->assertTrue($catalog->isTestMethod('ReservationServiceTest::testProtectedStillMatchesPrefix'));
    }

    public function testDoesNotGuessWhenTheParentCannotBeResolved(): void
    {
        $catalog = $this->detect([
            'tests/Unit/OrphanTest.php' => <<<'PHP'
<?php
class OrphanTest extends MissingBaseTest
{
    public function testSomething(): void
    {
    }
}
PHP,
        ]);

        $this->assertFalse($catalog->isTestSymbol('OrphanTest'));
        $this->assertFalse($catalog->isTestSymbol('OrphanTest::testSomething'));
    }

    public function testDoesNotTreatAClassUnderTestsAsATestWithoutPhpUnitEvidence(): void
    {
        $catalog = $this->detect([
            'tests/Support/Factory.php' => <<<'PHP'
<?php
class Factory
{
    public function testBuild(): void
    {
    }
}
PHP,
            'tests/Unit/LooksLikeATest.php' => <<<'PHP'
<?php
class LooksLikeATest
{
    public function testSomething(): void
    {
    }
}
PHP,
        ]);

        $this->assertTrue($catalog->isEmpty());
    }

    public function testDoesNotRequireTheTestsDirectory(): void
    {
        $catalog = $this->detect([
            'spec/OutsideTest.php' => <<<'PHP'
<?php
class OutsideTest extends PHPUnit\Framework\TestCase
{
    public function testOutside(): void
    {
    }
}
PHP,
        ]);

        $this->assertTrue($catalog->isTestMethod('OutsideTest::testOutside'));
        $this->assertSame('spec/OutsideTest.php', $catalog->getTestSymbol('OutsideTest::testOutside')?->file);
    }

    public function testTraitUsageDoesNotClassifyTheTraitAsATestClass(): void
    {
        $catalog = $this->detect([
            'tests/Support/WithHelpers.php' => <<<'PHP'
<?php
trait WithHelpers
{
    public function testFromTrait(): void
    {
    }
}
PHP,
            'tests/Unit/UsesTraitTest.php' => <<<'PHP'
<?php
class UsesTraitTest extends PHPUnit\Framework\TestCase
{
    use WithHelpers;

    public function testOwn(): void
    {
    }
}
PHP,
        ]);

        $this->assertFalse($catalog->isTestSymbol('WithHelpers'));
        $this->assertFalse($catalog->isTestSymbol('WithHelpers::testFromTrait'));
        $this->assertTrue($catalog->isTestMethod('UsesTraitTest::testOwn'));
    }

    public function testCatalogLookupsAreDeterministic(): void
    {
        $catalog = $this->detect([
            'tests/BTest.php' => <<<'PHP'
<?php
class BTest extends PHPUnit\Framework\TestCase
{
    public function testB(): void
    {
    }
}
PHP,
            'tests/ATest.php' => <<<'PHP'
<?php
class ATest extends PHPUnit\Framework\TestCase
{
    public function testA(): void
    {
    }
}
PHP,
        ]);

        $this->assertSame(
            ['ATest', 'ATest::testA', 'BTest', 'BTest::testB'],
            array_map(static fn (TestSymbol $symbol): string => $symbol->id, $catalog->all()),
        );
        $this->assertNull($catalog->getTestSymbol('missing'));
        $this->assertFalse($catalog->isTestMethod('ATest'));
    }

    /**
     * @param array<string, string> $files
     */
    private function detect(array $files): TestCatalog
    {
        return (new TestSymbolDetector())->detect(IndexedPhp::index($files));
    }
}
