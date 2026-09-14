<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\ReadinessService;

final class ReadinessServiceTest extends TestCase
{
    public function testApplicationIsReady(): void
    {
        $service = new ReadinessService();

        $this->assertTrue($service->isReady());
    }
}
