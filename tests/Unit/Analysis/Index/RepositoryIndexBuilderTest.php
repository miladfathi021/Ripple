<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Index;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\PhpParseException;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Graph\DependencyGraphBuilder;
use Ripple\Analysis\Graph\ReverseDependencyGraphBuilder;
use Ripple\Analysis\Index\DuplicateSymbolException;
use Ripple\Analysis\Index\RepositoryIndexBuilder;
use Ripple\Tests\Support\TemporaryDirectory;

final class RepositoryIndexBuilderTest extends TestCase
{
    public function testBuildsExactCountsFromASmallRepository(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('src/A.php', <<<'PHP'
<?php
class A
{
    public function one(B $b): B
    {
        return new B();
    }

    public function two(): void
    {
        B::three();
    }
}
PHP);
        $directory->write('src/B.php', <<<'PHP'
<?php
class B
{
    public function three(): void
    {
    }
}
PHP);
        $directory->write('src/Empty.php', "<?php\n");

        $index = (new RepositoryIndexBuilder())->build($directory->path);

        $this->assertSame(['src/A.php', 'src/B.php', 'src/Empty.php'], $index->getPhpFiles());
        $this->assertSame(3, $index->phpFileCount());
        $this->assertCount(2, array_filter(
            $index->getSymbols(),
            static fn ($symbol): bool => $symbol->type->value === 'class',
        ));
        $this->assertCount(3, array_filter(
            $index->getSymbols(),
            static fn ($symbol): bool => $symbol->type->value === 'method',
        ));
        $this->assertSame(5, $index->symbolCount());
        $this->assertSame(4, $index->dependencyCount());
        $this->assertTrue($index->hasSymbol('A'));
        $this->assertTrue($index->hasSymbol('A::one'));
        $this->assertTrue($index->hasSymbol('A::two'));
        $this->assertTrue($index->hasSymbol('B'));
        $this->assertTrue($index->hasSymbol('B::three'));
        $this->assertNotSame([], $index->getDependenciesForFile('src/A.php'));
        $this->assertSame([], $index->getDependenciesForFile('src/Empty.php'));
        $this->assertNotNull($index->getParsedFile('src/A.php'));
        $this->assertCount(3, $index->getParsedFiles());
    }

    public function testDetectsDuplicateSymbolDefinitions(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('app/Services/PaymentService.php', "<?php\nclass PaymentService {}\n");
        $directory->write('legacy/PaymentService.php', "<?php\nclass PaymentService {}\n");

        try {
            (new RepositoryIndexBuilder())->build($directory->path);
            $this->fail('Expected DuplicateSymbolException to be thrown.');
        } catch (DuplicateSymbolException $exception) {
            $this->assertSame('PaymentService', $exception->symbolId);
            $this->assertSame(
                [
                    'app/Services/PaymentService.php',
                    'legacy/PaymentService.php',
                ],
                $exception->files,
            );
            $this->assertStringContainsString('Duplicate symbol detected:', $exception->getMessage());
            $this->assertStringContainsString('app/Services/PaymentService.php', $exception->getMessage());
            $this->assertStringContainsString('legacy/PaymentService.php', $exception->getMessage());
        }
    }

    public function testIndexesThisRepositoryFixturesWithoutDuplicateSymbols(): void
    {
        $index = (new RepositoryIndexBuilder())->build(dirname(__DIR__, 4));

        $this->assertContains('tests/Fixtures/php/simple-class.php', $index->getPhpFiles());
        $this->assertTrue($index->hasSymbol('Ripple\\Tests\\Fixtures\\SimpleClass\\Foo'));
        $this->assertTrue($index->hasSymbol('Ripple\\Tests\\Fixtures\\NamespacedClass\\ReservationService'));
        $this->assertTrue($index->hasSymbol('Ripple\\Tests\\Fixtures\\BracedNamespace\\ReservationService'));
        $this->assertTrue($index->hasSymbol('Ripple\\Tests\\Fixtures\\Uses\\ReservationService'));
    }

    public function testIndexesARepositoryWideServiceGraph(): void
    {
        $directory = $this->serviceGraphDirectory();
        $index = (new RepositoryIndexBuilder())->build($directory->path);
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult($index->getDependencies()),
            $index->getSymbols(),
        );
        $reverse = (new ReverseDependencyGraphBuilder())->build($graph);

        $this->assertTrue($graph->getNode('ReservationService')?->known);
        $this->assertTrue($graph->getNode('PaymentService')?->known);
        $this->assertTrue($graph->getNode('PaymentRepository')?->known);
        $this->assertTrue($graph->getNode('NotificationService')?->known);
        $this->assertTrue($graph->hasEdge('ReservationService::updateStatus', 'PaymentService::validate', 'method_call'));
        $this->assertTrue($graph->hasEdge('PaymentService::validate', 'PaymentRepository::update', 'method_call'));
        $this->assertTrue($graph->hasEdge('PaymentService::validate', 'NotificationService::send', 'method_call'));
        $this->assertSame(['ReservationService::updateStatus'], $reverse->getDependents('PaymentService::validate'));
        $this->assertSame(['PaymentService::validate'], $reverse->getDependents('PaymentRepository::update'));
        $this->assertNotContains('ReservationService::updateStatus', $reverse->getDependents('PaymentRepository::update'));
    }

    public function testIncludesIsolatedSymbolsAsKnownGraphNodes(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('src/UnusedService.php', "<?php\nclass UnusedService {}\n");
        $directory->write('src/UsedService.php', <<<'PHP'
<?php
class UsedService
{
    public function run(): void
    {
    }
}
PHP);

        $index = (new RepositoryIndexBuilder())->build($directory->path);
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult($index->getDependencies()),
            $index->getSymbols(),
        );

        $this->assertTrue($index->hasSymbol('UnusedService'));
        $this->assertTrue($graph->hasNode('UnusedService'));
        $this->assertTrue($graph->getNode('UnusedService')?->known);
        $this->assertTrue($graph->hasNode('UsedService'));
        $this->assertTrue($graph->hasNode('UsedService::run'));
    }

    public function testKeepsUnknownExternalDependencyTargets(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('src/PaymentService.php', <<<'PHP'
<?php
class PaymentService extends Illuminate\Database\Eloquent\Model
{
    public function saveModel(): void
    {
        $this->save();
    }
}
PHP);

        $index = (new RepositoryIndexBuilder())->build($directory->path);
        $graph = (new DependencyGraphBuilder())->build(
            new DependencyResult($index->getDependencies()),
            $index->getSymbols(),
        );

        $this->assertTrue($graph->hasNode('Illuminate\\Database\\Eloquent\\Model'));
        $this->assertFalse($graph->getNode('Illuminate\\Database\\Eloquent\\Model')?->known);
        $this->assertTrue($graph->hasEdge('PaymentService', 'Illuminate\\Database\\Eloquent\\Model', 'extends'));
    }

    public function testParseErrorsIdentifyTheFile(): void
    {
        $directory = TemporaryDirectory::create();
        $directory->write('app/Services/BrokenService.php', "<?php\nclass Broken {\n");

        try {
            (new RepositoryIndexBuilder())->build($directory->path);
            $this->fail('Expected PhpParseException to be thrown.');
        } catch (PhpParseException $exception) {
            $this->assertSame('app/Services/BrokenService.php', $exception->sourceFile);
            $this->assertStringContainsString('Failed to parse app/Services/BrokenService.php', $exception->getMessage());
        }
    }

    private function serviceGraphDirectory(): TemporaryDirectory
    {
        $directory = TemporaryDirectory::create();
        $directory->write('src/ReservationService.php', <<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(PaymentService $payment): void
    {
        $payment->validate();
    }
}
PHP);
        $directory->write('src/PaymentService.php', <<<'PHP'
<?php
class PaymentService
{
    public function validate(PaymentRepository $repository, NotificationService $notifier): void
    {
        $repository->update();
        $notifier->send();
    }
}
PHP);
        $directory->write('src/PaymentRepository.php', <<<'PHP'
<?php
class PaymentRepository
{
    public function update(): void
    {
    }
}
PHP);
        $directory->write('src/NotificationService.php', <<<'PHP'
<?php
class NotificationService
{
    public function send(): void
    {
    }
}
PHP);

        return $directory;
    }
}
