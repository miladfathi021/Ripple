<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\ChangedFile;
use Ripple\Git\ChangeType;
use Ripple\Git\DiffResult;

final class AnalysisResultTest extends TestCase
{
    public function testSuccessfulResultExposesDiffData(): void
    {
        $diff = new DiffResult([
            new ChangedFile('src/Example.php', ChangeType::Modified, [11, 12], [10]),
        ]);
        $result = new AnalysisResult(status: 'ok', diff: $diff);

        $this->assertSame('ok', $result->status);
        $this->assertSame('', $result->message);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame($diff, $result->diff);
        $this->assertNull($result->graph);
        $this->assertNull($result->reverseGraph);
        $this->assertNull($result->repositoryIndex);
        $this->assertNull($result->directImpact);
        $this->assertNull($result->blastRadius);
        $this->assertNull($result->riskFactors);
        $this->assertNull($result->riskScore);
        $this->assertNull($result->affectedFlows);
        $this->assertNull($result->semanticImpact);
        $this->assertNull($result->churn);
        $this->assertNull($result->testImpact);
    }

    public function testErrorResultIsNotSuccessful(): void
    {
        $result = new AnalysisResult(
            status: 'error',
            message: "Ripple could not analyze this directory:\nnot a Git repository.",
        );

        $this->assertSame('error', $result->status);
        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->diff);
    }
}
