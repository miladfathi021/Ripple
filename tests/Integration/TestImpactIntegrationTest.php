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

final class TestImpactIntegrationTest extends TestCase
{
    public function testPipelineReportsDirectAndIndirectPhpUnitTestImpact(): void
    {
        $path = $this->fixtureRepository();
        $result = (new AnalysisRunner(new GitRepository($path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(
            'App\\Services\\ReservationService::updateStatus',
            $result->changedSymbols?->changedSymbols[0]->symbol->fullyQualifiedName,
        );
        $this->assertNotNull($result->testImpact);
        $this->assertSame(
            [
                [
                    'App\\Services\\ReservationService::updateStatus',
                    'Tests\\Unit\\ReservationServiceTest::documents_status_update',
                    'tests/Unit/ReservationServiceTest.php',
                    1,
                    'direct',
                ],
                [
                    'App\\Services\\ReservationService::updateStatus',
                    'Tests\\Unit\\ReservationServiceTest::testUpdateStatus',
                    'tests/Unit/ReservationServiceTest.php',
                    1,
                    'direct',
                ],
                [
                    'App\\Services\\ReservationService::updateStatus',
                    'Tests\\Feature\\PaymentTest::testReservationPayment',
                    'tests/Feature/PaymentTest.php',
                    2,
                    'indirect',
                ],
            ],
            array_map(
                static fn ($impact): array => [
                    $impact->changedSymbolId,
                    $impact->test->id,
                    $impact->test->file,
                    $impact->depth,
                    $impact->impact->value,
                ],
                $result->testImpact->impacts,
            ),
        );
        $this->assertSame(11, $result->riskScore?->score()->value());
        $this->assertNotNull($result->semanticImpact);
        $this->assertNotNull($result->churn);
    }

    public function testCliAndJsonReportAffectedTestsWithoutGuessingFromFilenames(): void
    {
        $tester = new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($this->fixtureRepository())),
            new ReportFormatterFactory(),
        ));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $text = $tester->getDisplay();
        $this->assertStringContainsString(
            <<<'TEXT'
Test impact:
  Direct:
    tests/Unit/ReservationServiceTest.php
      Tests\Unit\ReservationServiceTest::documents_status_update
      Tests\Unit\ReservationServiceTest::testUpdateStatus

  Indirect:
    tests/Feature/PaymentTest.php
      Tests\Feature\PaymentTest::testReservationPayment
        depth: 2
TEXT,
            $text,
        );
        $this->assertStringNotContainsString('LooksLikeATest', $text);
        $this->assertStringNotContainsString('mutation', strtolower($text));
        $this->assertStringContainsString('11/100 — Low', $text);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(11, $payload['risk_score']['score']);
        $this->assertSame('deep_impact', $payload['risk_score']['contributions'][0]['code']);
        $this->assertSame(
            [
                [
                    'changed_symbol' => 'App\\Services\\ReservationService::updateStatus',
                    'test_symbol' => 'Tests\\Unit\\ReservationServiceTest::documents_status_update',
                    'test_file' => 'tests/Unit/ReservationServiceTest.php',
                    'depth' => 1,
                    'impact' => 'direct',
                ],
                [
                    'changed_symbol' => 'App\\Services\\ReservationService::updateStatus',
                    'test_symbol' => 'Tests\\Unit\\ReservationServiceTest::testUpdateStatus',
                    'test_file' => 'tests/Unit/ReservationServiceTest.php',
                    'depth' => 1,
                    'impact' => 'direct',
                ],
                [
                    'changed_symbol' => 'App\\Services\\ReservationService::updateStatus',
                    'test_symbol' => 'Tests\\Feature\\PaymentTest::testReservationPayment',
                    'test_file' => 'tests/Feature/PaymentTest.php',
                    'depth' => 2,
                    'impact' => 'indirect',
                ],
            ],
            $payload['test_impact'],
        );
    }

    public function testNoTestsProducesAnEmptyTestImpactResult(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', $this->phpClass(1), 'initial');
        $repository->write('src/Example.php', $this->phpClass(2));

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->testImpact);
        $this->assertTrue($result->testImpact->isEmpty());
    }

    private function fixtureRepository(): string
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->files() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write(
            'src/Services/ReservationService.php',
            str_replace('$ok = true;', '$ok = false;', $this->files()['src/Services/ReservationService.php']),
        );

        return $repository->path;
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        return [
            'src/Services/ReservationService.php' => <<<'PHP'
<?php

namespace App\Services;

class ReservationService
{
    public function updateStatus(): void
    {
        $ok = true;
    }
}
PHP,
            'src/Services/PaymentService.php' => <<<'PHP'
<?php

namespace App\Services;

class PaymentService
{
    public function validate(ReservationService $reservation): void
    {
        $reservation->updateStatus();
    }
}
PHP,
            'tests/Unit/ReservationServiceTest.php' => <<<'PHP'
<?php

namespace Tests\Unit;

use App\Services\ReservationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReservationServiceTest extends TestCase
{
    public function testUpdateStatus(): void
    {
        (new ReservationService())->updateStatus();
    }

    #[Test]
    public function documents_status_update(): void
    {
        (new ReservationService())->updateStatus();
    }

    public function helper(): void
    {
    }
}
PHP,
            'tests/Feature/PaymentTest.php' => <<<'PHP'
<?php

namespace Tests\Feature;

use App\Services\PaymentService;
use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    public function testReservationPayment(): void
    {
        (new PaymentService())->validate(new \App\Services\ReservationService());
    }
}
PHP,
            'tests/Unit/LooksLikeATest.php' => <<<'PHP'
<?php

namespace Tests\Unit;

class LooksLikeATest
{
    public function testSomething(): void
    {
    }
}
PHP,
        ];
    }

    private function phpClass(int $value): string
    {
        return <<<PHP
<?php

class Example
{
    public function run(): void
    {
        \$value = {$value};
    }
}

PHP;
    }
}
