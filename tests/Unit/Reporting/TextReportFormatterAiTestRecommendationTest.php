<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Ripple\AI\Testing\AITestRecommendation;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\Reporting\TextReportFormatter;

final class TextReportFormatterAiTestRecommendationTest extends TestCase
{
    public function testGeneratedRecommendationAppearsOnce(): void
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
            "AI test recommendations\n────────────────────────\nConsider updating ReservationServiceTest::testUpdateStatus()\nbecause the changed symbol is directly covered by an impacted test.",
            $output,
        );
    }

    public function testMissingOrEmptyRecommendationDoesNotAddAHeading(): void
    {
        $formatter = new TextReportFormatter();
        $result = new AnalysisResult(status: 'ok', diff: new DiffResult([]));

        $this->assertStringNotContainsString('AI test recommendations', $formatter->format($result));
        $this->assertStringNotContainsString(
            'AI test recommendations',
            $formatter->format($result, null, null, AITestRecommendation::none()),
        );
    }
}
