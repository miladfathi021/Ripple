<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;

final class AnalysisRunnerTest extends TestCase
{
    public function testRunReturnsAReadyResult(): void
    {
        $runner = new AnalysisRunner();
        $result = $runner->run();

        $this->assertSame('ready', $result->status);
        $this->assertSame('Ripple is ready.', $result->message);
        $this->assertTrue($result->isSuccessful());
    }
}
