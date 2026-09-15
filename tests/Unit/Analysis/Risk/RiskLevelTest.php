<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Risk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Risk\RiskLevel;

final class RiskLevelTest extends TestCase
{
    #[DataProvider('scoreLevels')]
    public function testMapsScoreToLevel(int $score, RiskLevel $expected): void
    {
        $this->assertSame($expected, RiskLevel::fromScore($score));
    }

    /**
     * @return array<string, array{int, RiskLevel}>
     */
    public static function scoreLevels(): array
    {
        return [
            '0 low' => [0, RiskLevel::Low],
            '29 low' => [29, RiskLevel::Low],
            '30 medium' => [30, RiskLevel::Medium],
            '59 medium' => [59, RiskLevel::Medium],
            '60 high' => [60, RiskLevel::High],
            '100 high' => [100, RiskLevel::High],
        ];
    }
}
