<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\CLI\ApplicationFactory;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ApplicationBootTest extends TestCase
{
    public function testApplicationBootsAndAnalyzeCommandSucceeds(): void
    {
        $application = (new ApplicationFactory())->create();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $statusCode = $tester->run(['command' => 'analyze']);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('Ripple is ready.', $tester->getDisplay());
    }

    public function testHelpListsTheAnalyzeCommand(): void
    {
        $application = (new ApplicationFactory())->create();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $statusCode = $tester->run(['command' => 'help']);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('analyze', $tester->getDisplay());
    }
}
