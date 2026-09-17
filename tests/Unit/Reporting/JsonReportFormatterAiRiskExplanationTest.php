<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskLevel;
use Ripple\Analysis\Risk\RiskScore;
use Ripple\Analysis\Risk\RiskScoreContribution;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Risk\RiskSeverity;
use Ripple\Git\DiffResult;
use Ripple\Reporting\JsonReportFormatter;

final class JsonReportFormatterAiRiskExplanationTest extends TestCase
{
    public function testGeneratedRiskExplanationDoesNotChangeDeterministicRiskScore(): void
    {
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            riskScore: new RiskScoreResult(
                RiskScore::of(67),
                RiskLevel::High,
                [
                    new RiskScoreContribution(
                        RiskFactor::CODE_HIGH_FAN_IN,
                        'High fan-in',
                        RiskSeverity::Warning,
                        20,
                        15,
                    ),
                ],
            ),
        );
        $formatter = new JsonReportFormatter();
        $withoutAi = json_decode($formatter->format($result), true, 512, JSON_THROW_ON_ERROR);
        $withAi = json_decode(
            $formatter->format(
                $result,
                null,
                new AIRiskExplanation('Ripple rated this change High Risk with a score of 67.', true),
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($withoutAi['risk_score'], $withAi['risk_score']);
        $this->assertSame(67, $withAi['risk_score']['score']);
        $this->assertSame('high', $withAi['risk_score']['level']);
        $this->assertArrayNotHasKey('ai_risk_explanation', $withoutAi);
        $this->assertSame(
            [
                'generated' => true,
                'text' => 'Ripple rated this change High Risk with a score of 67.',
            ],
            $withAi['ai_risk_explanation'],
        );
    }

    public function testEmptyRiskExplanationIsOmitted(): void
    {
        $payload = json_decode(
            (new JsonReportFormatter())->format(
                new AnalysisResult(status: 'ok', diff: new DiffResult([])),
                null,
                AIRiskExplanation::none(),
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
    }
}
