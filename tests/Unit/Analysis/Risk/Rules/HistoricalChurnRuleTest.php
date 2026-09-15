<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk\Rules;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Analysis\Risk\Rules\HistoricalChurnRule;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;
use Ripple\Tests\Unit\Analysis\Risk\RiskFactorTestHelpers;

final class HistoricalChurnRuleTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testNoChurnEmitsNothing(): void
    {
        $this->assertSame([], (new HistoricalChurnRule())->evaluate($this->context()));
    }

    public function testEmptyChurnEmitsNothing(): void
    {
        $this->assertSame([], (new HistoricalChurnRule())->evaluate(
            $this->context(churn: ChurnResult::empty()),
        ));
    }

    #[DataProvider('belowThresholdCounts')]
    public function testBelowThresholdEmitsNothing(int $commits): void
    {
        $this->assertSame([], (new HistoricalChurnRule())->evaluate(
            $this->context(churn: $this->churnResult([
                'src/Services/ReservationService.php' => $commits,
            ])),
        ));
    }

    /**
     * @return array<string, list<int>>
     */
    public static function belowThresholdCounts(): array
    {
        return [
            '0 commits' => [0],
            '1 commit' => [1],
            '9 commits' => [9],
        ];
    }

    #[DataProvider('severityCounts')]
    public function testCommitCountMapsToSeverity(
        int $commits,
        RiskSeverity $severity,
        string $description,
    ): void {
        $factors = (new HistoricalChurnRule())->evaluate(
            $this->context(churn: $this->churnResult([
                'src/Services/ReservationService.php' => $commits,
            ])),
        );

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_HISTORICAL_CHURN, $factors[0]->code);
        $this->assertSame('Historical churn', $factors[0]->title);
        $this->assertSame($severity, $factors[0]->severity);
        $this->assertSame($commits, $factors[0]->value);
        $this->assertSame($description, $factors[0]->description);
        $this->assertSame(
            [
                'max_commit_count' => $commits,
                'affected_files' => [
                    [
                        'file' => 'src/Services/ReservationService.php',
                        'commit_count' => $commits,
                    ],
                ],
            ],
            $factors[0]->evidence,
        );
    }

    /**
     * @return array<string, array{int, RiskSeverity, string}>
     */
    public static function severityCounts(): array
    {
        return [
            'exactly 10 info' => [10, RiskSeverity::Info, 'ReservationService.php has changed 10 times in Git history.'],
            '24 info' => [24, RiskSeverity::Info, 'ReservationService.php has changed 24 times in Git history.'],
            'exactly 25 warning' => [25, RiskSeverity::Warning, 'ReservationService.php has changed 25 times in Git history.'],
            '49 warning' => [49, RiskSeverity::Warning, 'ReservationService.php has changed 49 times in Git history.'],
            'exactly 50 high' => [50, RiskSeverity::High, 'ReservationService.php has changed 50 times in Git history.'],
            '100 high' => [100, RiskSeverity::High, 'ReservationService.php has changed 100 times in Git history.'],
        ];
    }

    public function testHighestSeverityWinsAcrossMultipleFiles(): void
    {
        $factors = (new HistoricalChurnRule())->evaluate(
            $this->context(churn: $this->churnResult([
                'src/A.php' => 8,
                'src/B.php' => 17,
                'src/C.php' => 42,
                'src/D.php' => 61,
            ])),
        );

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
        $this->assertSame(61, $factors[0]->value);
        $this->assertSame(
            [
                ['file' => 'src/D.php', 'commit_count' => 61],
                ['file' => 'src/C.php', 'commit_count' => 42],
                ['file' => 'src/B.php', 'commit_count' => 17],
            ],
            $factors[0]->evidence['affected_files'],
        );
        $this->assertSame(
            "Frequently changed files:\n  src/D.php — 61 commits\n  src/C.php — 42 commits\n  src/B.php — 17 commits",
            $factors[0]->description,
        );
    }

    public function testSameSeverityIsOrderedByCommitCountThenPath(): void
    {
        $factors = (new HistoricalChurnRule())->evaluate(
            $this->context(churn: $this->churnResult([
                'src/B.php' => 57,
                'src/A.php' => 61,
                'src/C.php' => 57,
            ])),
        );

        $this->assertCount(1, $factors);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
        $this->assertSame(
            [
                ['file' => 'src/A.php', 'commit_count' => 61],
                ['file' => 'src/B.php', 'commit_count' => 57],
                ['file' => 'src/C.php', 'commit_count' => 57],
            ],
            $factors[0]->evidence['affected_files'],
        );
    }

    public function testDoesNotCreateSeparateFactorsPerFile(): void
    {
        $factors = (new HistoricalChurnRule())->evaluate(
            $this->context(churn: $this->churnResult([
                'src/ReservationService.php' => 12,
                'src/PaymentService.php' => 27,
                'src/NotificationService.php' => 63,
            ])),
        );

        $this->assertCount(1, $factors);
        $this->assertSame(RiskFactor::CODE_HISTORICAL_CHURN, $factors[0]->code);
        $this->assertSame(RiskSeverity::High, $factors[0]->severity);
    }

    public function testAllFilesBelowThresholdEmitNothing(): void
    {
        $this->assertSame([], (new HistoricalChurnRule())->evaluate(
            $this->context(churn: $this->churnResult([
                'src/A.php' => 0,
                'src/B.php' => 9,
                'src/C.php' => 1,
            ])),
        ));
    }

    public function testOnlyChurnEntriesAreConsidered(): void
    {
        $factors = (new HistoricalChurnRule())->evaluate(
            $this->context(
                ['BlastRadiusSymbol'],
                [],
                ['BlastRadiusSymbol' => 3],
                $this->churnResult(['src/Changed.php' => 12]),
            ),
        );

        $this->assertCount(1, $factors);
        $this->assertSame(
            [
                ['file' => 'src/Changed.php', 'commit_count' => 12],
            ],
            $factors[0]->evidence['affected_files'],
        );
    }

    /**
     * @param array<string, int> $commitCounts
     */
    private function churnResult(array $commitCounts): ChurnResult
    {
        $files = [];
        foreach ($commitCounts as $file => $commits) {
            $files[] = new FileChurn($file, $commits, 99, 99, 99, null);
        }

        return new ChurnResult($files);
    }
}
