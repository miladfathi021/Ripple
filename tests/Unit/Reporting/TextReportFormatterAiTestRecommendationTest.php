<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Explanation\AIPrExplanation;
use Ripple\AI\Explanation\AIRiskExplanation;
use Ripple\AI\Testing\AITestRecommendation;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\Reporting\JsonReportFormatter;
use Ripple\Reporting\TextReportFormatter;

final class TextReportFormatterAiTestRecommendationTest extends TestCase
{
    public function testGeneratedRecommendationAppearsAsANumberedList(): void
    {
        $output = (new TextReportFormatter())->format(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
            null,
            null,
            AITestRecommendation::generated(
                "Consider updating ReservationServiceTest::testUpdateStatus()\nbecause the changed symbol is directly covered by an impacted test.",
            ),
        );

        $this->assertStringContainsString(
            "AI Test Recommendations\n───────────────────────\n1. Consider updating ReservationServiceTest::testUpdateStatus() because the changed symbol is directly covered by an impacted test.",
            $output,
        );
    }

    public function testMissingOrEmptyRecommendationDoesNotAddAHeading(): void
    {
        $formatter = new TextReportFormatter();
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));

        $this->assertStringNotContainsString('AI Test Recommendations', $formatter->format($result));
        $this->assertStringNotContainsString(
            'AI Test Recommendations',
            $formatter->format($result, null, null, AITestRecommendation::none()),
        );
    }

    public function testLimitsRecommendationsAndNotesTheRemainder(): void
    {
        $recommendations = implode("\n", [
            'Update ReservationServiceTest::testUpdateStatus',
            'Update PaymentServiceTest::testCharge',
            'Review NotificationServiceTest::testSend',
            'Consider adding ReservationControllerTest::testCancel',
            'Consider adding AuditServiceTest::testRecord',
            'Consider adding BillingServiceTest::testInvoice',
            'Consider adding GatewayTest::testRetry',
        ]);
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));
        $generated = AITestRecommendation::generated($recommendations);

        $output = (new TextReportFormatter())->format($result, null, null, $generated);

        $this->assertStringContainsString('1. Update ReservationServiceTest::testUpdateStatus', $output);
        $this->assertStringContainsString('5. Consider adding AuditServiceTest::testRecord', $output);
        $this->assertStringNotContainsString('BillingServiceTest::testInvoice', $output);
        $this->assertStringContainsString('... and 2 more recommendations', $output);

        $payload = json_decode(
            (new JsonReportFormatter())->format($result, null, null, $generated),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($recommendations, $payload['ai_test_recommendations']['text']);
    }

    public function testTruncatesLongAiProseInTextButNotInJson(): void
    {
        $long = str_repeat('This change may affect the reservation update flow. ', 20);
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));
        $explanation = new AIPrExplanation($long, true);

        $text = (new TextReportFormatter())->format($result, $explanation);
        $this->assertStringContainsString('AI Explanation', $text);
        $this->assertStringContainsString('...', $text);
        $this->assertLessThan(strlen($long), strlen($text));
        $this->assertStringNotContainsString($long, $text);

        $payload = json_decode(
            (new JsonReportFormatter())->format($result, $explanation),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($long, $payload['ai_explanation']['text']);
    }

    public function testShowsConciseAiRiskExplanation(): void
    {
        $output = (new TextReportFormatter())->format(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
            null,
            new AIRiskExplanation('Ripple rated this change High Risk with a score of 67.', true),
        );

        $this->assertStringContainsString("AI Risk\n───────\nRipple rated this change High Risk with a score of 67.", $output);
        $this->assertStringContainsString('Risk: 0 / 100 (Low)', $output);
    }

    public function testStripsListPrefixesAndTruncatesLongRecommendations(): void
    {
        $long = str_repeat('review the reservation payment retry path ', 10);
        $output = (new TextReportFormatter())->format(
            new AnalysisResult(status: 'ok', diff: new DiffResult([])),
            null,
            null,
            AITestRecommendation::generated("1. Update ReservationServiceTest::testUpdateStatus\n- {$long}"),
        );

        $this->assertStringContainsString('1. Update ReservationServiceTest::testUpdateStatus', $output);
        $this->assertStringContainsString('2. review the reservation payment retry path', $output);
        $this->assertStringNotContainsString($long, $output);

        $recommendationLine = '';
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '2. ')) {
                $recommendationLine = $line;
                break;
            }
        }
        $this->assertNotSame('', $recommendationLine);
        $this->assertLessThanOrEqual(163, strlen($recommendationLine));
        $this->assertStringEndsWith('...', $recommendationLine);
    }
}
