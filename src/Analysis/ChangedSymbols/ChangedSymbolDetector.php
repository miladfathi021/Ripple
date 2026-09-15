<?php

declare(strict_types=1);

namespace Ripple\Analysis\ChangedSymbols;

use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\DiffResult;

final class ChangedSymbolDetector
{
    /**
     * @param array<string, list<Symbol>> $symbolsByFile
     */
    public function detect(DiffResult $diff, array $symbolsByFile): ChangedSymbolResult
    {
        $changedSymbols = [];
        $unmappedLines = [];
        $unmappedDeletedLines = [];

        foreach ($diff->files as $file) {
            if (!$this->isPhpFile($file->path)) {
                continue;
            }

            $fileResult = $this->detectFile($file, $symbolsByFile[$file->path] ?? []);
            foreach ($fileResult['symbols'] as $changedSymbol) {
                $changedSymbols[] = $changedSymbol;
            }
            if ($fileResult['unmapped'] !== []) {
                $unmappedLines[] = new UnmappedFileLines($file->path, $fileResult['unmapped']);
            }
            if ($fileResult['unmappedDeleted'] !== []) {
                $unmappedDeletedLines[] = new UnmappedFileLines($file->path, $fileResult['unmappedDeleted']);
            }
        }

        return new ChangedSymbolResult(
            changedSymbols: $this->sortChangedSymbols($changedSymbols),
            unmappedLines: $this->sortUnmapped($unmappedLines),
            unmappedDeletedLines: $this->sortUnmapped($unmappedDeletedLines),
        );
    }

    /**
     * @param list<Symbol> $symbols
     * @return array{
     *     symbols: list<ChangedSymbol>,
     *     unmapped: list<int>,
     *     unmappedDeleted: list<int>
     * }
     */
    private function detectFile(ChangedFile $file, array $symbols): array
    {
        $unmappedDeleted = $this->uniqueSorted($file->deletedLines);

        if ($file->changeType === ChangeType::Deleted) {
            return [
                'symbols' => [],
                'unmapped' => [],
                'unmappedDeleted' => $unmappedDeleted,
            ];
        }

        $grouped = [];
        $unmapped = [];

        foreach ($this->uniqueSorted($file->addedLines) as $line) {
            $symbol = $this->mostSpecificSymbol($symbols, $line);
            if ($symbol === null) {
                $unmapped[] = $line;
                continue;
            }

            $key = $symbol->fullyQualifiedName . ':' . $symbol->startLine . ':' . $symbol->endLine;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'symbol' => $symbol,
                    'lines' => [],
                ];
            }
            $grouped[$key]['lines'][] = $line;
        }

        $changedSymbols = [];
        foreach ($grouped as $group) {
            $changedSymbols[] = new ChangedSymbol($group['symbol'], $group['lines']);
        }

        return [
            'symbols' => $this->sortChangedSymbols($changedSymbols),
            'unmapped' => $unmapped,
            'unmappedDeleted' => $unmappedDeleted,
        ];
    }

    /**
     * @param list<Symbol> $symbols
     */
    private function mostSpecificSymbol(array $symbols, int $line): ?Symbol
    {
        $containing = [];
        foreach ($symbols as $symbol) {
            if ($symbol->startLine <= $line && $line <= $symbol->endLine) {
                $containing[] = $symbol;
            }
        }

        if ($containing === []) {
            return null;
        }

        usort($containing, function (Symbol $left, Symbol $right): int {
            $rangeComparison = ($left->endLine - $left->startLine) <=> ($right->endLine - $right->startLine);
            if ($rangeComparison !== 0) {
                return $rangeComparison;
            }

            return $this->specificity($right) <=> $this->specificity($left);
        });

        return $containing[0];
    }

    private function specificity(Symbol $symbol): int
    {
        return match ($symbol->type) {
            SymbolType::Method, SymbolType::Function => 2,
            default => 1,
        };
    }

    private function isPhpFile(string $path): bool
    {
        return str_ends_with(strtolower($path), '.php');
    }

    /**
     * @param list<int> $lines
     * @return list<int>
     */
    private function uniqueSorted(array $lines): array
    {
        $lines = array_values(array_unique($lines));
        sort($lines, SORT_NUMERIC);

        return $lines;
    }

    /**
     * @param list<ChangedSymbol> $symbols
     * @return list<ChangedSymbol>
     */
    private function sortChangedSymbols(array $symbols): array
    {
        usort($symbols, static function (ChangedSymbol $left, ChangedSymbol $right): int {
            $fileComparison = $left->symbol->file <=> $right->symbol->file;
            if ($fileComparison !== 0) {
                return $fileComparison;
            }

            $startComparison = $left->symbol->startLine <=> $right->symbol->startLine;
            if ($startComparison !== 0) {
                return $startComparison;
            }

            return $left->symbol->fullyQualifiedName <=> $right->symbol->fullyQualifiedName;
        });

        return $symbols;
    }

    /**
     * @param list<UnmappedFileLines> $entries
     * @return list<UnmappedFileLines>
     */
    private function sortUnmapped(array $entries): array
    {
        usort($entries, static fn (UnmappedFileLines $left, UnmappedFileLines $right): int => $left->file <=> $right->file);

        return $entries;
    }
}
