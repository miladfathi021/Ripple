<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskFactorAnalyzer;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;

final class RiskFactorAnalyzerTest extends TestCase
{
    use RiskFactorTestHelpers;

    public function testEmptyAnalysisEmitsNoFactors(): void
    {
        $result = (new RiskFactorAnalyzer())->analyze($this->context());

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->all());
    }

    public function testCombinesRulesInDeterministicOrder(): void
    {
        $edges = [];
        for ($i = 0; $i < 10; $i++) {
            $edges[] = $this->edge('D' . sprintf('%02d', $i), 'A', DependencyType::MethodCall);
        }
        $edges[] = $this->edge('D00', 'A', DependencyType::ParameterType);
        $edges[] = $this->edge('D01', 'A', DependencyType::StaticCall);
        $edges[] = $this->edge('T1', 'D00', DependencyType::MethodCall);
        $edges[] = $this->edge('T2', 'T1', DependencyType::MethodCall);
        $edges[] = $this->edge('T3', 'T2', DependencyType::MethodCall);

        $blast = [
            'D00' => 1,
            'D01' => 1,
            'D02' => 1,
            'D03' => 1,
            'D04' => 1,
            'D05' => 1,
            'D06' => 1,
            'D07' => 1,
            'D08' => 1,
            'D09' => 1,
            'T1' => 2,
            'T2' => 3,
            'T3' => 4,
        ];

        $first = (new RiskFactorAnalyzer())->analyze($this->context(['A', 'B', 'C', 'D'], $edges, $blast));
        $second = (new RiskFactorAnalyzer())->analyze($this->context(['D', 'C', 'B', 'A'], $edges, $blast));

        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
            ],
            array_map(static fn (RiskFactor $factor): string => $factor->code, $first->all()),
        );
        $this->assertSame(RiskSeverity::High, $first->all()[0]->severity);
        $this->assertSame(10, $first->all()[0]->value);
        $this->assertSame(13, $first->all()[1]->value);
        $this->assertSame(4, $first->all()[2]->value);
        $this->assertSame(['method_call', 'parameter_type', 'static_call'], $first->all()[3]->evidence['dependency_types']);
        $this->assertSame(4, $first->all()[4]->value);
        $this->assertSame(
            array_map(static fn (RiskFactor $factor): array => [$factor->code, $factor->value, $factor->evidence], $first->all()),
            array_map(static fn (RiskFactor $factor): array => [$factor->code, $factor->value, $factor->evidence], $second->all()),
        );
    }

    public function testIncludesExactlyOneHistoricalChurnFactorFromContext(): void
    {
        $result = (new RiskFactorAnalyzer())->analyze($this->context(
            churn: $this->churnResult([
                'src/A.php' => 12,
                'src/B.php' => 63,
            ]),
        ));

        $codes = array_map(static fn (RiskFactor $factor): string => $factor->code, $result->all());
        $this->assertSame([RiskFactor::CODE_HISTORICAL_CHURN], $codes);
        $this->assertSame(RiskSeverity::High, $result->all()[0]->severity);
        $this->assertSame(63, $result->all()[0]->value);
    }

    /**
     * @param array<string, int> $commitCounts
     */
    private function churnResult(array $commitCounts): ChurnResult
    {
        $files = [];
        foreach ($commitCounts as $file => $commits) {
            $files[] = new FileChurn($file, $commits, 0, 0, 0, null);
        }

        return new ChurnResult($files);
    }
}
