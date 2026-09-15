<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use Error;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskScoreContribution;
use Ripple\Analysis\Risk\RiskSeverity;

final class RiskScoreContributionTest extends TestCase
{
    public function testStoresCorrectValues(): void
    {
        $contribution = new RiskScoreContribution(
            code: 'high_fan_in',
            title: 'High fan-in',
            severity: RiskSeverity::Warning,
            maxWeight: 25,
            contribution: 19,
        );

        $this->assertSame('high_fan_in', $contribution->code);
        $this->assertSame('High fan-in', $contribution->title);
        $this->assertSame(RiskSeverity::Warning, $contribution->severity);
        $this->assertSame(25, $contribution->maxWeight);
        $this->assertSame(19, $contribution->contribution);
    }

    public function testIsImmutable(): void
    {
        $contribution = new RiskScoreContribution(
            'high_fan_in',
            'High fan-in',
            RiskSeverity::High,
            25,
            25,
        );

        $this->expectException(Error::class);
        $contribution->contribution = 0;
    }
}
