<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Git\GitRepository;
use Ripple\Reporting\ReportFormatterFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TransitiveImpactIntegrationTest extends TestCase
{
    public function testCliReportsDirectImpactAndBlastRadiusFromRepositoryWideGraph(): void
    {
        $tester = $this->commandTester($this->serviceGraphRepository());
        $statusCode = $tester->execute(['--format' => 'text']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Changed symbols:', $display);
        $this->assertStringContainsString('ReservationService::updateStatus()', $display);
        $this->assertStringContainsString(
            <<<'TEXT'
Direct impact:

  AuditService::record()
    ← method_call

  PaymentService::validate()
    ← method_call
TEXT,
            $display,
        );
        $this->assertStringContainsString(
            <<<'TEXT'
Blast radius:
  Depth 1:
    AuditService::record()
    PaymentService::validate()

  Depth 2:
    NotificationService::send()
    PaymentRepository::update()
TEXT,
            $display,
        );
        $this->assertStringNotContainsString('UnusedService', $display);
        $this->assertMatchesRegularExpression(
            '/Direct impact:.*Blast radius:/s',
            $display,
        );
        preg_match('/Direct impact:(.*)Blast radius:/s', $display, $sections);
        $this->assertArrayHasKey(1, $sections);
        $this->assertStringNotContainsString('PaymentRepository::update', $sections[1]);
        $this->assertStringNotContainsString('NotificationService::send', $sections[1]);
        $this->assertStringNotContainsString('UnusedService', $sections[1]);
    }

    public function testJsonKeepsDirectImpactAndBlastRadiusSeparate(): void
    {
        $tester = $this->commandTester($this->serviceGraphRepository());
        $statusCode = $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'AuditService::record',
                'PaymentService::validate',
            ],
            array_column($payload['direct_impact'], 'impacted_symbol'),
        );
        $this->assertSame(
            [
                'AuditService::record',
                'PaymentService::validate',
                'NotificationService::send',
                'PaymentRepository::update',
            ],
            array_column($payload['blast_radius'], 'impacted_symbol'),
        );
        $this->assertSame([1, 1, 2, 2], array_column($payload['blast_radius'], 'depth'));
        $this->assertNotContains('UnusedService', array_column($payload['blast_radius'], 'impacted_symbol'));
        $this->assertNotContains(
            'PaymentRepository::update',
            array_column($payload['direct_impact'], 'impacted_symbol'),
        );
    }

    private function serviceGraphRepository(): string
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->serviceGraphFiles() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write(
            'src/ReservationService.php',
            str_replace('$ok = true;', '$ok = false;', $this->serviceGraphFiles()['src/ReservationService.php']),
        );

        return $repository->path;
    }

    private function commandTester(string $workingDirectory): CommandTester
    {
        $command = new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
        );

        return new CommandTester($command);
    }

    /**
     * @return array<string, string>
     */
    private function serviceGraphFiles(): array
    {
        return [
            'src/ReservationService.php' => <<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(): void
    {
        $ok = true;
    }
}
PHP,
            'src/PaymentService.php' => <<<'PHP'
<?php
class PaymentService
{
    public function validate(ReservationService $reservation): void
    {
        $reservation->updateStatus();
    }
}
PHP,
            'src/AuditService.php' => <<<'PHP'
<?php
class AuditService
{
    public function record(ReservationService $reservation): void
    {
        $reservation->updateStatus();
    }
}
PHP,
            'src/PaymentRepository.php' => <<<'PHP'
<?php
class PaymentRepository
{
    public function update(PaymentService $payment): void
    {
        $payment->validate();
    }
}
PHP,
            'src/NotificationService.php' => <<<'PHP'
<?php
class NotificationService
{
    public function send(PaymentService $payment): void
    {
        $payment->validate();
    }
}
PHP,
            'src/UnusedService.php' => <<<'PHP'
<?php
class UnusedService
{
}
PHP,
        ];
    }
}
