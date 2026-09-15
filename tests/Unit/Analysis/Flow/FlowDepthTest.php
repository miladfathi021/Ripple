<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Flow;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Flow\FlowDepth;
use Ripple\Analysis\Flow\InvalidFlowDepthException;

final class FlowDepthTest extends TestCase
{
    public function testDefaultIsThree(): void
    {
        $this->assertSame(3, FlowDepth::default()->value);
        $this->assertSame(3, FlowDepth::DEFAULT);
    }

    public function testAcceptsZero(): void
    {
        $this->assertSame(0, (new FlowDepth(0))->value);
    }

    public function testRejectsNegativeDepth(): void
    {
        $this->expectException(InvalidFlowDepthException::class);
        $this->expectExceptionMessage('Flow depth must be 0 or greater, got -1.');
        new FlowDepth(-1);
    }
}
