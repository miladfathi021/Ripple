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

final class AffectedFlowIntegrationTest extends TestCase
{
    public function testDownstreamFlowsAreSeparateFromBlastRadius(): void
    {
        $path = $this->chainRepository();
        $first = (new AnalysisRunner(new GitRepository($path)))->run();
        $second = (new AnalysisRunner(new GitRepository($path)))->run();

        $this->assertTrue($first->isSuccessful());
        $this->assertSame(
            'ReservationService::updateStatus',
            $first->changedSymbols?->changedSymbols[0]->symbol->fullyQualifiedName,
        );
        $this->assertTrue($first->directImpact?->isEmpty());
        $this->assertTrue($first->blastRadius?->isEmpty());
        $this->assertNotNull($first->affectedFlows);
        $this->assertFalse($first->affectedFlows->isEmpty());

        $snapshots = [];
        foreach ($first->affectedFlows->all() as $flow) {
            $snapshots[] = [
                $flow->changedSymbolId,
                $flow->type->value,
                $flow->depth,
                array_map(static fn ($node): string => $node->id, $flow->nodes),
            ];
        }

        $this->assertContains(
            [
                'ReservationService::updateStatus',
                'call_chain',
                2,
                ['PaymentService::validate', 'PaymentRepository::update'],
            ],
            $snapshots,
        );
        $this->assertSame($snapshots, array_map(
            static fn ($flow): array => [
                $flow->changedSymbolId,
                $flow->type->value,
                $flow->depth,
                array_map(static fn ($node): string => $node->id, $flow->nodes),
            ],
            $second->affectedFlows?->all() ?? [],
        ));
    }

    public function testCliAndJsonIncludeAffectedFlowsDeterministically(): void
    {
        $tester = $this->commandTester($this->chainRepository());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $text = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $this->assertSame($text, $tester->getDisplay());

        $this->assertStringContainsString('Ripple Analysis', $text);
        $this->assertStringContainsString('Blast radius:', $text);
        $this->assertStringNotContainsString('Direct impact:', $text);
        $this->assertStringNotContainsString('Affected flows:', $text);
        $this->assertStringNotContainsString('Database', $text);
        $this->assertStringNotContainsString('Queue', $text);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $firstJson = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $this->assertSame($firstJson, $tester->getDisplay());

        $payload = json_decode($firstJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload['direct_impact']);
        $this->assertSame([], $payload['blast_radius']);
        $callChain = null;
        foreach ($payload['affected_flows'] as $flow) {
            if (
                $flow['type'] === 'call_chain'
                && ($flow['nodes'][1]['id'] ?? null) === 'PaymentRepository::update'
            ) {
                $callChain = $flow;
            }
        }
        $this->assertNotNull($callChain);
        $this->assertSame('ReservationService::updateStatus', $callChain['changed_symbol']);
        $this->assertSame(2, $callChain['depth']);
        $this->assertSame('method_call', $callChain['edges'][0]['dependency_type']);
        $this->assertSame('ReservationService::updateStatus', $callChain['edges'][0]['source']);
        $this->assertSame('PaymentService::validate', $callChain['edges'][0]['target']);
    }

    private function commandTester(string $workingDirectory): CommandTester
    {
        return new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
        ));
    }

    private function chainRepository(): string
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->files() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write(
            'src/ReservationService.php',
            str_replace('$ok = true;', '$ok = false;', $this->files()['src/ReservationService.php']),
        );

        return $repository->path;
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        return [
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
