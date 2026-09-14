<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisResult;

final class AnalysisResultTest extends TestCase
{
    public function testReadyResultExposesStatusAndMessage(): void
    {
        $result = new AnalysisResult('ready', 'Ripple is ready.');

        $this->assertSame('ready', $result->status);
        $this->assertSame('Ripple is ready.', $result->message);
        $this->assertTrue($result->isSuccessful());
    }

    public function testErrorResultIsNotSuccessful(): void
    {
        $result = new AnalysisResult('error', 'Ripple is not ready.');

        $this->assertSame('error', $result->status);
        $this->assertFalse($result->isSuccessful());
    }
}
