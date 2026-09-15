<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use Error;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Analysis\Risk\RiskSeverity;

final class RiskFactorTest extends TestCase
{
    public function testExposesAllFields(): void
    {
        $factor = new RiskFactor(
            code: RiskFactor::CODE_LARGE_BLAST_RADIUS,
            severity: RiskSeverity::Warning,
            title: 'Large blast radius',
            description: '5 symbols may be affected by this change.',
            value: 5,
            evidence: ['affected_symbols' => 5],
        );

        $this->assertSame(RiskFactor::CODE_LARGE_BLAST_RADIUS, $factor->code);
        $this->assertSame(RiskSeverity::Warning, $factor->severity);
        $this->assertSame('warning', $factor->severity->value);
        $this->assertSame('Large blast radius', $factor->title);
        $this->assertSame('5 symbols may be affected by this change.', $factor->description);
        $this->assertSame(5, $factor->value);
        $this->assertSame(['affected_symbols' => 5], $factor->evidence);
    }

    public function testIsImmutable(): void
    {
        $factor = new RiskFactor(
            code: RiskFactor::CODE_DEEP_IMPACT,
            severity: RiskSeverity::High,
            title: 'Deep dependency chain',
            description: 'The change can reach impacted symbols up to 4 dependency levels away.',
            value: 4,
            evidence: ['max_depth' => 4],
        );

        $this->expectException(Error::class);
        $factor->value = 99;
    }
}
