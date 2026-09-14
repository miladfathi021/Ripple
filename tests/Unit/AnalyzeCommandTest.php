<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Reporting\ReportFormatterFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeCommandTest extends TestCase
{
    public function testTextOutputIndicatesRippleIsReady(): void
    {
        $tester = $this->commandTester();
        $statusCode = $tester->execute(['--format' => 'text']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString('🌊 Ripple', $tester->getDisplay());
        $this->assertStringContainsString('Ripple is ready.', $tester->getDisplay());
        $this->assertStringNotContainsString('"status"', $tester->getDisplay());
    }

    public function testJsonOutputIndicatesRippleIsReady(): void
    {
        $tester = $this->commandTester();
        $statusCode = $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $statusCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'status' => 'ready',
                'message' => 'Ripple is ready.',
            ],
            $payload,
        );
    }

    private function commandTester(): CommandTester
    {
        $command = new AnalyzeCommand(
            new AnalysisRunner(),
            new ReportFormatterFactory(),
        );

        return new CommandTester($command);
    }
}
