<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Index;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Index\RepositoryIndex;

final class RepositoryIndexTest extends TestCase
{
    public function testStoresFilesSymbolsAndDependenciesWithStableOrdering(): void
    {
        $payment = $this->classSymbol('PaymentService', 'src/B.php');
        $reservation = $this->classSymbol('ReservationService', 'src/A.php');
        $dependency = new Dependency(
            'ReservationService::update',
            'PaymentService::validate',
            DependencyType::MethodCall,
            [10],
            1,
        );

        $index = new RepositoryIndex(
            phpFiles: ['src/A.php', 'src/B.php'],
            symbols: [$payment, $reservation],
            dependencies: [$dependency],
            symbolsByFile: [
                'src/A.php' => [$reservation],
                'src/B.php' => [$payment],
            ],
            dependenciesByFile: [
                'src/A.php' => [$dependency],
            ],
            symbolsById: [
                $payment->fullyQualifiedName => $payment,
                $reservation->fullyQualifiedName => $reservation,
            ],
        );

        $this->assertSame(['src/A.php', 'src/B.php'], $index->getPhpFiles());
        $this->assertSame([$payment, $reservation], $index->getSymbols());
        $this->assertSame([$dependency], $index->getDependencies());
        $this->assertTrue($index->hasSymbol('ReservationService'));
        $this->assertFalse($index->hasSymbol('Unknown'));
        $this->assertSame($reservation, $index->getSymbol('ReservationService'));
        $this->assertSame([$reservation], $index->getSymbolsForFile('src/A.php'));
        $this->assertSame([], $index->getSymbolsForFile('missing.php'));
        $this->assertSame([$dependency], $index->getDependenciesForFile('src/A.php'));
        $this->assertSame([], $index->getDependenciesForFile('src/B.php'));
        $this->assertSame(2, $index->phpFileCount());
        $this->assertSame(2, $index->symbolCount());
        $this->assertSame(1, $index->dependencyCount());
    }

    private function classSymbol(string $name, string $file): Symbol
    {
        return new Symbol(SymbolType::Class_, $name, $name, $file, 1, 10);
    }
}
