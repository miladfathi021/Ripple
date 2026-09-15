<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\AnalysisRunner;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Git\GitRepository;
use Ripple\Tests\Support\TemporaryGitRepository;

final class HistoricalChurnIntegrationTest extends TestCase
{
    public function testEightCommitsDoNotProduceHistoricalChurn(): void
    {
        $result = $this->analyze($this->repositoryWithCommits([
            'src/ReservationService.php' => 8,
        ]));

        $this->assertTrue($result->isSuccessful());
        $this->assertNotContains(
            RiskFactor::CODE_HISTORICAL_CHURN,
            array_map(static fn (RiskFactor $factor): string => $factor->code, $result->riskFactors?->all() ?? []),
        );
    }

    public function testThirtyOneCommitsProduceAWarning(): void
    {
        $result = $this->analyze($this->repositoryWithCommits([
            'src/ReservationService.php' => 31,
        ]));

        $factor = $this->historicalChurn($result->riskFactors?->all() ?? []);
        $this->assertNotNull($factor);
        $this->assertSame(RiskSeverity::Warning, $factor->severity);
        $this->assertSame(31, $factor->value);
        $this->assertSame(
            'ReservationService.php has changed 31 times in Git history.',
            $factor->description,
        );
        $this->assertSame(19, $result->riskScore?->score()->value());
    }

    public function testMultipleFilesProduceExactlyOneHighFactor(): void
    {
        $result = $this->analyze($this->repositoryWithCommits([
            'src/ReservationService.php' => 12,
            'src/PaymentService.php' => 27,
            'src/NotificationService.php' => 63,
        ]));

        $codes = array_map(
            static fn (RiskFactor $factor): string => $factor->code,
            $result->riskFactors?->all() ?? [],
        );
        $this->assertSame(1, count(array_keys($codes, RiskFactor::CODE_HISTORICAL_CHURN, true)));
        $factor = $this->historicalChurn($result->riskFactors?->all() ?? []);
        $this->assertNotNull($factor);
        $this->assertSame(RiskSeverity::High, $factor->severity);
        $this->assertSame(63, $factor->value);
        $this->assertSame(
            [
                ['file' => 'src/NotificationService.php', 'commit_count' => 63],
                ['file' => 'src/PaymentService.php', 'commit_count' => 27],
                ['file' => 'src/ReservationService.php', 'commit_count' => 12],
            ],
            $factor->evidence['affected_files'],
        );
        $this->assertSame(
            25,
            $this->contributionFor($result, RiskFactor::CODE_HISTORICAL_CHURN),
        );
    }

    public function testHistoricalChurnCombinesWithExistingFactorsOnce(): void
    {
        $repository = TemporaryGitRepository::create();
        $hub = <<<'PHP'
<?php
class Hub
{
    public function run(): void
    {
        $ok = true;
    }
}
PHP;
        $extra = <<<'PHP'
<?php
class Extra
{
    public function noop(): void
    {
        $x = 1;
    }
}
PHP;
        $leaf = <<<'PHP'
<?php
class Leaf
{
    public function work(Caller5 $caller): void
    {
        $caller->go();
    }
}
PHP;
        for ($i = 1; $i <= 31; $i++) {
            $repository->commitFile('src/Hub.php', str_replace('$ok = true;', '$ok = ' . $i . ';', $hub), "hub {$i}");
        }
        $repository->commitFile('src/Extra.php', $extra, 'extra');
        $repository->commitFile('src/Leaf.php', $leaf, 'leaf');
        $repository->commitFile('src/Caller1.php', <<<'PHP'
<?php
class Caller1
{
    public function go(): void
    {
        Hub::run();
    }
}
PHP, 'caller1');
        for ($i = 2; $i <= 5; $i++) {
            $repository->commitFile("src/Caller{$i}.php", <<<PHP
<?php
class Caller{$i}
{
    public function go(Hub \$hub): void
    {
        \$hub->run();
    }
}
PHP, "caller{$i}");
        }
        $repository->write('src/Hub.php', str_replace('$ok = true;', '$ok = false;', $hub));
        $repository->write('src/Extra.php', str_replace('$x = 1;', '$x = 2;', $extra));

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();
        $codes = array_map(
            static fn (RiskFactor $factor): string => $factor->code,
            $result->riskFactors?->all() ?? [],
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
                RiskFactor::CODE_HISTORICAL_CHURN,
            ],
            $codes,
        );
        $this->assertSame(70, $result->riskScore?->score()->value());
        $this->assertSame('high', $result->riskScore?->level()->value);
        $this->assertSame(
            [15, 15, 11, 5, 5, 19],
            array_map(
                static fn ($contribution): int => $contribution->contribution,
                $result->riskScore?->contributions() ?? [],
            ),
        );
        $this->assertSame(RiskSeverity::Warning, $this->historicalChurn($result->riskFactors->all())?->severity);
    }

    /**
     * @param array<string, int> $commitCounts
     */
    private function repositoryWithCommits(array $commitCounts): TemporaryGitRepository
    {
        $repository = TemporaryGitRepository::create();
        foreach ($commitCounts as $path => $commits) {
            $class = basename($path, '.php');
            for ($i = 1; $i <= $commits; $i++) {
                $repository->commitFile($path, $this->phpClass($class, $i), "{$class} {$i}");
            }
            $repository->write($path, $this->phpClass($class, $commits + 1));
        }

        return $repository;
    }

    private function analyze(TemporaryGitRepository $repository): AnalysisResult
    {
        return (new AnalysisRunner(new GitRepository($repository->path)))->run();
    }

    private function contributionFor(AnalysisResult $result, string $code): ?int
    {
        foreach ($result->riskScore?->contributions() ?? [] as $contribution) {
            if ($contribution->code === $code) {
                return $contribution->contribution;
            }
        }

        return null;
    }

    /**
     * @param list<RiskFactor> $factors
     */
    private function historicalChurn(array $factors): ?RiskFactor
    {
        foreach ($factors as $factor) {
            if ($factor->code === RiskFactor::CODE_HISTORICAL_CHURN) {
                return $factor;
            }
        }

        return null;
    }

    private function phpClass(string $class, int $value): string
    {
        $token = str_repeat($class, 8);

        return <<<PHP
<?php
class {$class}
{
    private string \$identity = '{$token}';

    public function {$class}Run(): void
    {
        \$value = {$value};
    }
}
PHP;
    }
}
