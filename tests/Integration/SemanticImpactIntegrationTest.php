<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Semantics\FlowSemanticType;
use Ripple\CLI\ApplicationFactory;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Reporting\ReportFormatterFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SemanticImpactIntegrationTest extends TestCase
{
    public function testExplicitAnnotationsClassifyBlastRadiusAndAffectedFlowsSeparately(): void
    {
        $path = $this->chainRepository($this->validConfig());
        $first = ApplicationFactory::runnerForWorkingDirectory($path)->run();
        $second = ApplicationFactory::runnerForWorkingDirectory($path)->run();

        $this->assertTrue($first->isSuccessful());
        $this->assertSame(
            'ReservationService::updateStatus',
            $first->changedSymbols?->changedSymbols[0]->symbol->fullyQualifiedName,
        );
        $this->assertSame(
            ['ReservationController::update'],
            $first->blastRadius?->getImpactedSymbolIds(),
        );
        $this->assertNotNull($first->affectedFlows);
        $this->assertContains(
            'PaymentRepository::update',
            $this->flowNodeIds($first->affectedFlows->all()),
        );
        $this->assertNotContains(
            'ReservationController::update',
            $this->flowNodeIds($first->affectedFlows->all()),
        );
        $this->assertFalse($first->graph?->hasNode('App\\Future\\NotIndexed::handle'));

        $this->assertNotNull($first->semanticImpact);
        $this->assertSame(
            [['ReservationController::update', 'api_entrypoint']],
            $this->snapshots($first->semanticImpact->blastRadiusAnnotations),
        );
        $this->assertSame(
            [['PaymentRepository::update', 'database_write']],
            $this->snapshots($first->semanticImpact->affectedFlowAnnotations),
        );
        $this->assertSame(
            $this->snapshots($first->semanticImpact->blastRadiusAnnotations),
            $this->snapshots($second->semanticImpact?->blastRadiusAnnotations ?? []),
        );
        $this->assertSame(
            $this->snapshots($first->semanticImpact->affectedFlowAnnotations),
            $this->snapshots($second->semanticImpact?->affectedFlowAnnotations ?? []),
        );
    }

    public function testCliAndJsonReportSemanticImpactWithoutNameGuessing(): void
    {
        $tester = $this->commandTester($this->chainRepository($this->validConfig()));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $text = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $this->assertSame($text, $tester->getDisplay());

        $this->assertStringContainsString('Ripple Analysis', $text);
        $this->assertStringNotContainsString('Semantic impact:', $text);
        $this->assertStringNotContainsString('[queue]', $text);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                [
                    'symbol' => 'ReservationController::update',
                    'type' => 'api_entrypoint',
                ],
            ],
            $payload['semantic_impact']['blast_radius'],
        );
        $this->assertSame(
            [
                [
                    'symbol' => 'PaymentRepository::update',
                    'type' => 'database_write',
                ],
            ],
            $payload['semantic_impact']['affected_flows'],
        );
        $this->assertContains('api_entrypoint', FlowSemanticType::values());
    }

    public function testMissingConfigLeavesSemanticImpactEmpty(): void
    {
        $path = $this->chainRepository(null);
        $result = ApplicationFactory::runnerForWorkingDirectory($path)->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->semanticImpact);
        $this->assertTrue($result->semanticImpact->isEmpty());
        $this->assertNotSame([], $result->blastRadius?->getImpactedSymbolIds() ?? []);
        $this->assertFalse($result->affectedFlows?->isEmpty());
    }

    public function testInvalidConfigFailsAnalysis(): void
    {
        $path = $this->chainRepository('{"semantic_annotations": [{"symbol": "A::run"}]}');
        $result = ApplicationFactory::runnerForWorkingDirectory($path)->run();

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Ripple could not load semantic configuration:', $result->message);
        $this->assertStringContainsString('missing required key "type"', $result->message);
        $this->assertNull($result->graph);
    }

    private function commandTester(string $workingDirectory): CommandTester
    {
        return new CommandTester(new AnalyzeCommand(
            ApplicationFactory::runnerForWorkingDirectory($workingDirectory),
            new ReportFormatterFactory(),
        ));
    }

    private function chainRepository(?string $config): string
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->files() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write(
            'src/ReservationService.php',
            str_replace('$ok = true;', '$ok = false;', $this->files()['src/ReservationService.php']),
        );
        if ($config !== null) {
            $repository->write('ripple.json', $config);
        }

        return $repository->path;
    }

    private function validConfig(): string
    {
        return <<<'JSON'
{
  "semantic_annotations": [
    {
      "symbol": "ReservationController::update",
      "type": "api_entrypoint"
    },
    {
      "symbol": "PaymentRepository::update",
      "type": "database_write"
    },
    {
      "symbol": "App\\Future\\NotIndexed::handle",
      "type": "queue"
    }
  ]
}
JSON;
    }

    /**
     * @param list<\Ripple\Analysis\Flow\AffectedFlow> $flows
     * @return list<string>
     */
    private function flowNodeIds(array $flows): array
    {
        $ids = [];
        foreach ($flows as $flow) {
            foreach ($flow->nodes as $node) {
                $ids[] = $node->id;
            }
        }

        return $ids;
    }

    /**
     * @param list<\Ripple\Analysis\Semantics\SemanticAnnotation> $annotations
     * @return list<array{string, string}>
     */
    private function snapshots(array $annotations): array
    {
        return array_map(
            static fn ($annotation): array => [$annotation->symbolId, $annotation->type->value],
            $annotations,
        );
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        return [
            'src/ReservationController.php' => <<<'PHP'
<?php
class ReservationController
{
    public function update(ReservationService $service): void
    {
        $service->updateStatus();
    }
}
PHP,
            'src/ReservationService.php' => <<<'PHP'
<?php
class ReservationService
{
    public function updateStatus(PaymentService $payment): void
    {
        $ok = true;
        $payment->validate();
    }
}
PHP,
            'src/PaymentService.php' => <<<'PHP'
<?php
class PaymentService
{
    public function validate(PaymentRepository $repo): void
    {
        $repo->update();
    }
}
PHP,
            'src/PaymentRepository.php' => <<<'PHP'
<?php
class PaymentRepository
{
    public function update(): void
    {
        $done = true;
    }
}
PHP,
        ];
    }
}
