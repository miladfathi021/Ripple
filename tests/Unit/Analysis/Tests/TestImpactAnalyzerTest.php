<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Tests;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\GraphEdge;
use Ripple\Analysis\Impact\BlastRadiusEntry;
use Ripple\Analysis\Impact\BlastRadiusOrigin;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Tests\TestCatalog;
use Ripple\Analysis\Tests\TestImpactAnalyzer;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Analysis\Tests\TestImpactType;
use Ripple\Analysis\Tests\TestSymbol;
use Ripple\Analysis\Tests\TestSymbolType;

final class TestImpactAnalyzerTest extends TestCase
{
    public function testDirectTestImpactHasDepthOne(): void
    {
        $result = $this->analyze(
            [
                $this->entry('Tests\\Unit\\FooTest::testBar', 1, [
                    $this->origin('App\\Foo::bar', 1, 'Tests\\Unit\\FooTest::testBar', 'App\\Foo::bar'),
                ]),
            ],
            [$this->method('Tests\\Unit\\FooTest::testBar', 'tests/Unit/FooTest.php', 'Tests\\Unit\\FooTest')],
        );

        $this->assertCount(1, $result->impacts);
        $this->assertSame('App\\Foo::bar', $result->impacts[0]->changedSymbolId);
        $this->assertSame('Tests\\Unit\\FooTest::testBar', $result->impacts[0]->test->id);
        $this->assertSame('tests/Unit/FooTest.php', $result->impacts[0]->test->file);
        $this->assertSame(1, $result->impacts[0]->depth);
        $this->assertSame(TestImpactType::Direct, $result->impacts[0]->impact);
    }

    public function testIndirectTestImpactUsesShortestDepth(): void
    {
        $result = $this->analyze(
            [
                $this->entry('Tests\\Feature\\PaymentTest::testFlow', 3, [
                    $this->origin(
                        'App\\Repositories\\PaymentRepository::update',
                        3,
                        'Tests\\Feature\\PaymentTest::testFlow',
                        'App\\Services\\PaymentService::validate',
                    ),
                ]),
            ],
            [$this->method('Tests\\Feature\\PaymentTest::testFlow', 'tests/Feature/PaymentTest.php', 'Tests\\Feature\\PaymentTest')],
        );

        $this->assertSame(3, $result->impacts[0]->depth);
        $this->assertSame(TestImpactType::Indirect, $result->impacts[0]->impact);
    }

    public function testMultipleChangedSymbolsProduceSeparateOriginRows(): void
    {
        $test = $this->method('Tests\\Feature\\FlowTest::testReservation', 'tests/Feature/FlowTest.php', 'Tests\\Feature\\FlowTest');
        $result = $this->analyze(
            [
                $this->entry('Tests\\Feature\\FlowTest::testReservation', 1, [
                    $this->origin('App\\Reservation::update', 1, $test->id, 'App\\Reservation::update'),
                    $this->origin('App\\Payment::validate', 2, $test->id, 'App\\Payment::validate'),
                ]),
            ],
            [$test],
        );

        $this->assertCount(2, $result->impacts);
        $this->assertSame('App\\Reservation::update', $result->impacts[0]->changedSymbolId);
        $this->assertSame(1, $result->impacts[0]->depth);
        $this->assertSame(TestImpactType::Direct, $result->impacts[0]->impact);
        $this->assertSame('App\\Payment::validate', $result->impacts[1]->changedSymbolId);
        $this->assertSame(2, $result->impacts[1]->depth);
        $this->assertCount(1, $result->uniqueTests());
    }

    public function testDuplicateOriginsForTheSameChangedSymbolAreCollapsed(): void
    {
        $test = $this->method('Tests\\Unit\\FooTest::testBar', 'tests/Unit/FooTest.php', 'Tests\\Unit\\FooTest');
        $result = $this->analyze(
            [
                $this->entry('Tests\\Unit\\FooTest::testBar', 1, [
                    $this->origin('App\\Foo::bar', 1, $test->id, 'App\\Foo::bar'),
                    $this->origin('App\\Foo::bar', 1, $test->id, 'App\\Foo::bar', DependencyType::StaticCall),
                ]),
            ],
            [$test],
        );

        $this->assertCount(1, $result->impacts);
    }

    public function testIgnoresNonTestBlastRadiusNodesAndUnknownSymbols(): void
    {
        $result = $this->analyze(
            [
                $this->entry('App\\PaymentService::validate', 1, [
                    $this->origin('App\\Foo::bar', 1, 'App\\PaymentService::validate', 'App\\Foo::bar'),
                ]),
                $this->entry('Unknown::ghost', 2, [
                    $this->origin('App\\Foo::bar', 2, 'Unknown::ghost', 'App\\Foo::bar'),
                ]),
            ],
            [$this->method('Tests\\Unit\\FooTest::testBar', 'tests/Unit/FooTest.php', 'Tests\\Unit\\FooTest')],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testIgnoresTestClassesWithoutTestMethodsInTheBlastRadius(): void
    {
        $result = $this->analyze(
            [
                $this->entry('Tests\\Unit\\FooTest', 1, [
                    $this->origin('App\\Foo::bar', 1, 'Tests\\Unit\\FooTest', 'App\\Foo::bar'),
                ]),
            ],
            [
                new TestSymbol(
                    'Tests\\Unit\\FooTest',
                    TestSymbolType::Class_,
                    'tests/Unit/FooTest.php',
                    'Tests\\Unit\\FooTest',
                    null,
                    3,
                    20,
                    'Tests\\Unit\\FooTest',
                ),
            ],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testEmptyBlastRadiusProducesNoImpact(): void
    {
        $result = $this->analyze(
            [],
            [$this->method('Tests\\Unit\\FooTest::testBar', 'tests/Unit/FooTest.php', 'Tests\\Unit\\FooTest')],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function testOrderingIsDepthThenFileThenTestThenChangedSymbol(): void
    {
        $a = $this->method('Tests\\BTest::testA', 'tests/b.php', 'Tests\\BTest');
        $b = $this->method('Tests\\ATest::testB', 'tests/a.php', 'Tests\\ATest');
        $result = $this->analyze(
            [
                $this->entry($a->id, 2, [
                    $this->origin('ChangedZ', 2, $a->id, 'ChangedZ'),
                    $this->origin('ChangedA', 2, $a->id, 'ChangedA'),
                ]),
                $this->entry($b->id, 1, [
                    $this->origin('ChangedM', 1, $b->id, 'ChangedM'),
                ]),
            ],
            [$a, $b],
        );

        $this->assertSame(
            [
                ['ChangedM', 'Tests\\ATest::testB', 1],
                ['ChangedA', 'Tests\\BTest::testA', 2],
                ['ChangedZ', 'Tests\\BTest::testA', 2],
            ],
            array_map(
                static fn ($impact): array => [$impact->changedSymbolId, $impact->test->id, $impact->depth],
                $result->impacts,
            ),
        );
    }

    /**
     * @param list<BlastRadiusEntry> $entries
     * @param list<TestSymbol> $tests
     */
    private function analyze(array $entries, array $tests): TestImpactResult
    {
        return (new TestImpactAnalyzer())->analyze(
            TransitiveImpactResult::fromEntries($entries),
            TestCatalog::fromSymbols($tests),
        );
    }

    /**
     * @param list<BlastRadiusOrigin> $origins
     */
    private function entry(string $impacted, int $depth, array $origins): BlastRadiusEntry
    {
        return new BlastRadiusEntry($impacted, $depth, $origins);
    }

    private function origin(
        string $changed,
        int $depth,
        string $source,
        string $target,
        DependencyType $type = DependencyType::MethodCall,
    ): BlastRadiusOrigin {
        return new BlastRadiusOrigin(
            $changed,
            $depth,
            new GraphEdge($source, $target, $type, [1], 1),
        );
    }

    private function method(string $id, string $file, string $class): TestSymbol
    {
        [$_class, $name] = explode('::', $id, 2);

        return TestSymbol::fromMethod(
            new Symbol(SymbolType::Method, $name, $id, $file, 5, 10, $class, 'public', false),
            $class,
        );
    }
}
