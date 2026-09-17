<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\AI\Testing\AITestRecommendation;
use Ripple\Analysis\AnalysisResult;
use Ripple\Analysis\Risk\RiskLevel;
use Ripple\Analysis\Risk\RiskScore;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Git\DiffResult;
use Ripple\Reporting\JsonReportFormatter;

final class JsonReportFormatterAiTestRecommendationTest extends TestCase
{
    public function testGeneratedRecommendationDoesNotChangeDeterministicAnalysis(): void
    {
        $result = new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
            riskScore: new RiskScoreResult(RiskScore::of(67), RiskLevel::High, []),
        );
        $formatter = new JsonReportFormatter();
        $withoutAi = json_decode($formatter->format($result), true, 512, JSON_THROW_ON_ERROR);
        $withAi = json_decode(
            $formatter->format(
                $result,
                null,
                null,
                AITestRecommendation::generated('Consider updating ReservationServiceTest::testUpdateStatus().'),
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($withoutAi['risk_score'], $withAi['risk_score']);
        $this->assertSame($withoutAi['test_impact'], $withAi['test_impact']);
        $this->assertArrayNotHasKey('ai_test_recommendations', $withoutAi);
        $this->assertSame(
            [
                'generated' => true,
                'text' => 'Consider updating ReservationServiceTest::testUpdateStatus().',
            ],
            $withAi['ai_test_recommendations'],
        );
    }

    public function testEmptyRecommendationIsOmitted(): void
    {
        $payload = json_decode(
            (new JsonReportFormatter())->format(
                new AnalysisResult(status: 'ok', diff: new DiffResult([])),
                null,
                null,
                AITestRecommendation::none(),
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
    }

    public function testGeneratedSectionsAppearIndependently(): void
    {
        $payload = json_decode(
            (new JsonReportFormatter())->format(
                new AnalysisResult(status: 'ok', diff: new DiffResult([])),
                new AIPrExplanation('This change may affect the reservation update flow.', true),
                new AIRiskExplanation('Ripple rated this change High Risk with a score of 67.', true),
                AITestRecommendation::generated('Consider updating ReservationServiceTest::testUpdateStatus().'),
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('This change may affect the reservation update flow.', $payload['ai_explanation']['text']);
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $payload['ai_risk_explanation']['text']);
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $payload['ai_test_recommendations']['text'],
        );
    }
}
