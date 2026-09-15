<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\ChangedSymbols;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolDetector;
use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\DiffResult;

final class ChangedSymbolDetectorTest extends TestCase
{
    public function testMapsAChangedLineInsideAMethodToTheMethodNotTheClass(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [25], []),
            [
                $this->class('ReservationService', 'src/Service.php', 10, 50),
                $this->method('updateStatus', 'ReservationService::updateStatus', 'src/Service.php', 20, 30, 'ReservationService'),
            ],
        );

        $this->assertCount(1, $result->changedSymbols);
        $this->assertSame('ReservationService::updateStatus', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame(SymbolType::Method, $result->changedSymbols[0]->symbol->type);
        $this->assertSame([25], $result->changedSymbols[0]->changedLines);
        $this->assertSame([], $result->unmappedLines);
    }

    public function testGroupsMultipleChangedLinesInOneMethod(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [22, 29, 25], []),
            [
                $this->class('ReservationService', 'src/Service.php', 10, 50),
                $this->method('updateStatus', 'ReservationService::updateStatus', 'src/Service.php', 20, 30, 'ReservationService'),
            ],
        );

        $this->assertCount(1, $result->changedSymbols);
        $this->assertSame([22, 25, 29], $result->changedSymbols[0]->changedLines);
    }

    public function testMapsChangedLinesToMultipleMethods(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [12, 35], []),
            [
                $this->class('ReservationService', 'src/Service.php', 1, 50),
                $this->method('methodA', 'ReservationService::methodA', 'src/Service.php', 10, 20, 'ReservationService'),
                $this->method('methodB', 'ReservationService::methodB', 'src/Service.php', 30, 40, 'ReservationService'),
            ],
        );

        $this->assertCount(2, $result->changedSymbols);
        $this->assertSame('ReservationService::methodA', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame([12], $result->changedSymbols[0]->changedLines);
        $this->assertSame('ReservationService::methodB', $result->changedSymbols[1]->symbol->fullyQualifiedName);
        $this->assertSame([35], $result->changedSymbols[1]->changedLines);
    }

    public function testMapsAClassLevelChangeOutsideMethodsToTheClass(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [12], []),
            [
                $this->class('ReservationService', 'src/Service.php', 10, 50),
                $this->method('updateStatus', 'ReservationService::updateStatus', 'src/Service.php', 20, 30, 'ReservationService'),
            ],
        );

        $this->assertCount(1, $result->changedSymbols);
        $this->assertSame(SymbolType::Class_, $result->changedSymbols[0]->symbol->type);
        $this->assertSame('ReservationService', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame([12], $result->changedSymbols[0]->changedLines);
    }

    public function testMapsAChangedLineToATopLevelFunction(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/helpers.php', [8], []),
            [
                new Symbol(SymbolType::Function, 'calculateTotal', 'App\\Helpers\\calculateTotal', 'src/helpers.php', 5, 12),
            ],
        );

        $this->assertSame('App\\Helpers\\calculateTotal', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame(SymbolType::Function, $result->changedSymbols[0]->symbol->type);
        $this->assertSame([8], $result->changedSymbols[0]->changedLines);
    }

    public function testMapsAChangedLineToAnInterfaceMethod(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Gateway.php', [6], []),
            [
                new Symbol(SymbolType::Interface, 'PaymentGateway', 'PaymentGateway', 'src/Gateway.php', 3, 10),
                $this->method('charge', 'PaymentGateway::charge', 'src/Gateway.php', 5, 7, 'PaymentGateway'),
            ],
        );

        $this->assertSame('PaymentGateway::charge', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame(SymbolType::Method, $result->changedSymbols[0]->symbol->type);
    }

    public function testMapsAChangedLineToATraitMethod(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Logs.php', [8], []),
            [
                new Symbol(SymbolType::Trait, 'LogsActivity', 'LogsActivity', 'src/Logs.php', 3, 12),
                $this->method('log', 'LogsActivity::log', 'src/Logs.php', 5, 10, 'LogsActivity'),
            ],
        );

        $this->assertSame('LogsActivity::log', $result->changedSymbols[0]->symbol->fullyQualifiedName);
    }

    public function testMapsAChangedLineToAnEnumMethod(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Status.php', [12], []),
            [
                new Symbol(SymbolType::Enum, 'ReservationStatus', 'ReservationStatus', 'src/Status.php', 3, 16),
                $this->method('label', 'ReservationStatus::label', 'src/Status.php', 10, 14, 'ReservationStatus'),
            ],
        );

        $this->assertSame('ReservationStatus::label', $result->changedSymbols[0]->symbol->fullyQualifiedName);
    }

    public function testSelectsTheMostSpecificOverlappingSymbol(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [35], []),
            [
                $this->class('ReservationService', 'src/Service.php', 10, 60),
                $this->method('updateStatus', 'ReservationService::updateStatus', 'src/Service.php', 30, 40, 'ReservationService'),
            ],
        );

        $this->assertCount(1, $result->changedSymbols);
        $this->assertSame('ReservationService::updateStatus', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame([35], $result->changedSymbols[0]->changedLines);
    }

    public function testRecordsUnmappedLinesOutsideAllSymbols(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [3, 4], []),
            [
                $this->class('ReservationService', 'src/Service.php', 10, 50),
            ],
        );

        $this->assertSame([], $result->changedSymbols);
        $this->assertCount(1, $result->unmappedLines);
        $this->assertSame('src/Service.php', $result->unmappedLines[0]->file);
        $this->assertSame([3, 4], $result->unmappedLines[0]->lines);
    }

    public function testDoesNotSendNonPhpFilesThroughSymbolDetection(): void
    {
        $result = (new ChangedSymbolDetector())->detect(
            new DiffResult([
                new ChangedFile('README.md', ChangeType::Modified, [1, 2], []),
                $this->phpFile('src/Service.php', [25], []),
            ]),
            [
                'src/Service.php' => [
                    $this->method('updateStatus', 'ReservationService::updateStatus', 'src/Service.php', 20, 30, 'ReservationService'),
                ],
            ],
        );

        $this->assertCount(1, $result->changedSymbols);
        $this->assertSame('src/Service.php', $result->changedSymbols[0]->symbol->file);
        $this->assertSame([], $result->unmappedLines);
    }

    public function testDetectsChangedSymbolsInAnAddedPhpFile(): void
    {
        $result = $this->detectPhp(
            new ChangedFile('src/NewService.php', ChangeType::Added, range(1, 20), []),
            [
                $this->class('NewService', 'src/NewService.php', 3, 20),
                $this->method('run', 'NewService::run', 'src/NewService.php', 5, 18, 'NewService'),
            ],
        );

        $this->assertSame('NewService', $result->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame([3, 4, 19, 20], $result->changedSymbols[0]->changedLines);
        $this->assertSame('NewService::run', $result->changedSymbols[1]->symbol->fullyQualifiedName);
        $this->assertSame(range(5, 18), $result->changedSymbols[1]->changedLines);
        $this->assertSame([1, 2], $result->unmappedLines[0]->lines);
    }

    public function testDeletedPhpFilesDoNotInventCurrentSymbols(): void
    {
        $result = $this->detectPhp(
            new ChangedFile('src/Gone.php', ChangeType::Deleted, [], [1, 2, 3]),
            [
                $this->class('Gone', 'src/Gone.php', 1, 10),
            ],
        );

        $this->assertSame([], $result->changedSymbols);
        $this->assertSame([], $result->unmappedLines);
        $this->assertCount(1, $result->unmappedDeletedLines);
        $this->assertSame('src/Gone.php', $result->unmappedDeletedLines[0]->file);
        $this->assertSame([1, 2, 3], $result->unmappedDeletedLines[0]->lines);
    }

    public function testTracksDeletedLinesSeparatelyWithoutMappingThem(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [25], [35, 36]),
            [
                $this->method('updateStatus', 'ReservationService::updateStatus', 'src/Service.php', 20, 30, 'ReservationService'),
            ],
        );

        $this->assertSame([25], $result->changedSymbols[0]->changedLines);
        $this->assertSame([35, 36], $result->unmappedDeletedLines[0]->lines);
    }

    public function testReturnsNoChangedSymbolsWhenAllLinesAreUnmapped(): void
    {
        $result = $this->detectPhp(
            $this->phpFile('src/Service.php', [1, 2], []),
            [
                $this->class('ReservationService', 'src/Service.php', 10, 50),
            ],
        );

        $this->assertSame([], $result->changedSymbols);
        $this->assertSame([1, 2], $result->unmappedLines[0]->lines);
    }

    /**
     * @param list<Symbol> $symbols
     */
    private function detectPhp(ChangedFile $file, array $symbols): ChangedSymbolResult
    {
        return (new ChangedSymbolDetector())->detect(
            new DiffResult([$file]),
            [$file->path => $symbols],
        );
    }

    /**
     * @param list<int> $added
     * @param list<int> $deleted
     */
    private function phpFile(string $path, array $added, array $deleted): ChangedFile
    {
        return new ChangedFile($path, ChangeType::Modified, $added, $deleted);
    }

    private function class(string $name, string $file, int $start, int $end): Symbol
    {
        return new Symbol(SymbolType::Class_, $name, $name, $file, $start, $end);
    }

    private function method(string $name, string $fqn, string $file, int $start, int $end, string $parent): Symbol
    {
        return new Symbol(SymbolType::Method, $name, $fqn, $file, $start, $end, $parent, 'public', false);
    }
}
