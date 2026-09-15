<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git\History;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ripple\Git\History\FileChurn;

final class FileChurnTest extends TestCase
{
    public function testExposesFileChurnFacts(): void
    {
        $lastChangedAt = new DateTimeImmutable('2026-09-14T13:42:10+00:00');
        $churn = new FileChurn(
            'src/Services/ReservationService.php',
            18,
            742,
            391,
            5,
            $lastChangedAt,
        );

        $this->assertSame('src/Services/ReservationService.php', $churn->file);
        $this->assertSame(18, $churn->commitCount);
        $this->assertSame(742, $churn->linesAdded);
        $this->assertSame(391, $churn->linesDeleted);
        $this->assertSame(5, $churn->contributorsCount);
        $this->assertSame($lastChangedAt, $churn->lastChangedAt);
    }

    public function testNoneRepresentsAFileWithNoHistory(): void
    {
        $churn = FileChurn::none('src/NewService.php');

        $this->assertSame('src/NewService.php', $churn->file);
        $this->assertSame(0, $churn->commitCount);
        $this->assertSame(0, $churn->linesAdded);
        $this->assertSame(0, $churn->linesDeleted);
        $this->assertSame(0, $churn->contributorsCount);
        $this->assertNull($churn->lastChangedAt);
    }
}
