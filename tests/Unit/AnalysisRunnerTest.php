<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\Git\ChangeType;
use Ripple\Git\GitRepository;
use Ripple\Tests\Support\TemporaryGitRepository;

final class AnalysisRunnerTest extends TestCase
{
    public function testRunReturnsDiffAnalysisForATrackedChange(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', $this->phpClass(1), 'initial');
        $repository->write('src/Example.php', $this->phpClass(2));

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertSame('ok', $result->status);
        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->diff);
        $this->assertCount(1, $result->diff->files);
        $this->assertSame('src/Example.php', $result->diff->files[0]->path);
        $this->assertSame(ChangeType::Modified, $result->diff->files[0]->changeType);
        $this->assertSame([7], $result->diff->files[0]->addedLines);
        $this->assertSame([7], $result->diff->files[0]->deletedLines);
        $this->assertNotNull($result->changedSymbols);
        $this->assertCount(1, $result->changedSymbols->changedSymbols);
        $this->assertSame('Example::run', $result->changedSymbols->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertSame([7], $result->changedSymbols->changedSymbols[0]->changedLines);
        $this->assertSame([7], $result->changedSymbols->unmappedDeletedLines[0]->lines);
        $this->assertNotNull($result->graph);
        $this->assertSame(['Example', 'Example::run'], array_map(
            static fn ($node): string => $node->id,
            $result->graph->getNodes(),
        ));
        $this->assertTrue($result->graph->getNode('Example')?->known);
        $this->assertTrue($result->graph->getNode('Example::run')?->known);
        $this->assertSame([], $result->graph->getEdges());
        $this->assertNotNull($result->reverseGraph);
        $this->assertSame(['Example', 'Example::run'], array_map(
            static fn ($node): string => $node->id,
            $result->reverseGraph->getNodes(),
        ));
        $this->assertSame([], $result->reverseGraph->getDependents('Example::run'));
        $this->assertNotNull($result->repositoryIndex);
        $this->assertSame(1, $result->repositoryIndex->phpFileCount());
        $this->assertSame(2, $result->repositoryIndex->symbolCount());
        $this->assertNotNull($result->directImpact);
        $this->assertTrue($result->directImpact->isEmpty());
        $this->assertNotNull($result->affectedFlows);
        $this->assertTrue($result->affectedFlows->isEmpty());
        $this->assertNotNull($result->semanticImpact);
        $this->assertTrue($result->semanticImpact->isEmpty());
        $this->assertNotNull($result->churn);
        $this->assertCount(1, $result->churn->files);
        $this->assertSame('src/Example.php', $result->churn->files[0]->file);
        $this->assertSame(1, $result->churn->files[0]->commitCount);
        $this->assertNotNull($result->churn->files[0]->lastChangedAt);
        $this->assertNotNull($result->testImpact);
        $this->assertTrue($result->testImpact->isEmpty());
    }

    public function testRunProducesADependencyGraphIncludingUnindexedTargets(): void
    {
        $repository = TemporaryGitRepository::create();
        $source = <<<'PHP'
<?php

class Example
{
    public function run(PaymentService $payment): void
    {
        $payment->validate();
    }
}

PHP;
        $repository->commitFile('src/Example.php', $source, 'initial');
        $repository->write('src/Example.php', str_replace(
            '$payment->validate();',
            "\$payment->validate();\n        \$changed = 1;",
            $source,
        ));

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->graph);
        $this->assertTrue($result->graph->hasNode('Example::run'));
        $this->assertTrue($result->graph->getNode('Example::run')?->known);
        $this->assertTrue($result->graph->hasNode('Example'));
        $this->assertTrue($result->graph->getNode('Example')?->known);
        $this->assertTrue($result->graph->hasNode('PaymentService'));
        $this->assertFalse($result->graph->getNode('PaymentService')?->known);
        $this->assertTrue($result->graph->hasNode('PaymentService::validate'));
        $this->assertFalse($result->graph->getNode('PaymentService::validate')?->known);
        $this->assertTrue($result->graph->hasEdge('Example::run', 'PaymentService', 'parameter_type'));
        $this->assertTrue($result->graph->hasEdge('Example::run', 'PaymentService::validate', 'method_call'));
        $this->assertNotNull($result->reverseGraph);
        $this->assertSame(['Example::run'], $result->reverseGraph->getDependents('PaymentService'));
        $this->assertSame(['Example::run'], $result->reverseGraph->getDependents('PaymentService::validate'));
        $this->assertSame([], $result->reverseGraph->getDependents('Example::run'));
        $this->assertFalse($result->reverseGraph->hasDependents('Example::run'));
        $this->assertNotNull($result->dependencies);
        $this->assertNotSame([], $result->dependencies->dependencies);
    }

    public function testRunReturnsAnErrorOutsideAGitRepository(): void
    {
        $directory = TemporaryGitRepository::emptyDirectory();
        $result = (new AnalysisRunner(new GitRepository($directory)))->run();

        $this->assertSame('error', $result->status);
        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->diff);
        $this->assertSame(
            "Ripple could not analyze this directory:\nnot a Git repository.",
            $result->message,
        );
        $this->assertStringNotContainsString('fatal:', $result->message);
    }

    public function testDeletedPhpFileDoesNotInventSymbols(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Gone.php', $this->phpClass(1), 'initial');
        $repository->delete('src/Gone.php');

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('src/Gone.php', $result->diff?->files[0]->path);
        $this->assertSame(ChangeType::Deleted, $result->diff->files[0]->changeType);
        $this->assertSame([], $result->changedSymbols?->changedSymbols);
        $this->assertNotSame([], $result->changedSymbols?->unmappedDeletedLines);
    }

    public function testIgnoresNonPhpFilesForSymbolDetection(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('README.md', "hello\n", 'initial');
        $repository->write('README.md', "hello world\n");

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('README.md', $result->diff?->files[0]->path);
        $this->assertSame([], $result->changedSymbols?->changedSymbols);
        $this->assertSame([], $result->changedSymbols?->unmappedLines);
        $this->assertSame([], $result->changedSymbols?->unmappedDeletedLines);
    }

    public function testSyntaxErrorBecomesADomainError(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Broken.php', "<?php\nclass Ok {}\n", 'initial');
        $repository->write('src/Broken.php', "<?php\nclass Ok {\n");

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertSame('error', $result->status);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Failed to parse src/Broken.php', $result->message);
        $this->assertStringContainsString('Ripple could not analyze PHP file:', $result->message);
        $this->assertStringContainsString('src/Broken.php', $result->message);
        $this->assertStringNotContainsString('Stack trace', $result->message);
        $this->assertStringNotContainsString('PhpParser\\Error', $result->message);
    }

    public function testRunIndexesTheWholeRepositoryWhileKeepingChangedSymbolsDiffSpecific(): void
    {
        $repository = TemporaryGitRepository::create();
        for ($i = 1; $i <= 20; $i++) {
            $body = $i === 1
                ? $this->phpClass(1)
                : "<?php\nclass File{$i} {}\n";
            $path = $i === 1 ? 'src/Example.php' : "src/File{$i}.php";
            $repository->commitFile($path, $body, "add {$path}");
        }
        $repository->write('src/Example.php', $this->phpClass(2));

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->repositoryIndex);
        $this->assertSame(20, $result->repositoryIndex->phpFileCount());
        $this->assertCount(1, $result->diff?->files ?? []);
        $this->assertSame('src/Example.php', $result->diff->files[0]->path);
        $this->assertNotNull($result->changedSymbols);
        $this->assertCount(1, $result->changedSymbols->changedSymbols);
        $this->assertSame('Example::run', $result->changedSymbols->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertTrue($result->graph?->hasNode('File20'));
        $this->assertTrue($result->graph->getNode('File20')?->known);
        $this->assertTrue($result->graph->hasNode('Example::run'));
        $this->assertNotNull($result->directImpact);
        $this->assertTrue($result->directImpact->isEmpty());
        $this->assertCount(1, $result->changedSymbols->changedSymbols);
        $this->assertGreaterThan(20, $result->repositoryIndex->symbolCount());
    }

    public function testRunReportsOnlyDirectImpactAndExcludesTransitiveDependents(): void
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->serviceGraphFiles() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write(
            'src/ReservationService.php',
            str_replace('$ok = true;', '$ok = false;', $this->serviceGraphFiles()['src/ReservationService.php']),
        );

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->changedSymbols?->changedSymbols ?? []);
        $this->assertSame(
            'ReservationService::updateStatus',
            $result->changedSymbols->changedSymbols[0]->symbol->fullyQualifiedName,
        );
        $this->assertNotNull($result->directImpact);
        $this->assertSame(
            ['AuditService::record', 'PaymentService::validate'],
            array_map(static fn ($symbol): string => $symbol->id, $result->directImpact->getImpactedSymbols()),
        );
        $impacted = array_map(static fn ($symbol): string => $symbol->id, $result->directImpact->getImpactedSymbols());
        $this->assertNotContains('PaymentRepository::update', $impacted);
        $this->assertNotContains('NotificationService::send', $impacted);
        $this->assertNotContains('UnusedService', $impacted);
        $this->assertSame('ReservationService::updateStatus', $result->directImpact->impacts[0]->edge->target);
        $this->assertSame('method_call', $result->directImpact->impacts[0]->edge->type->value);

        $this->assertNotNull($result->blastRadius);
        $this->assertSame(
            [
                'AuditService::record',
                'PaymentService::validate',
                'NotificationService::send',
                'PaymentRepository::update',
            ],
            $result->blastRadius->getImpactedSymbolIds(),
        );
        $this->assertSame(1, $result->blastRadius->getEntry('AuditService::record')?->depth);
        $this->assertSame(1, $result->blastRadius->getEntry('PaymentService::validate')?->depth);
        $this->assertSame(2, $result->blastRadius->getEntry('NotificationService::send')?->depth);
        $this->assertSame(2, $result->blastRadius->getEntry('PaymentRepository::update')?->depth);
        $this->assertNull($result->blastRadius->getEntry('UnusedService'));
        $this->assertNull($result->blastRadius->getEntry('ReservationService::updateStatus'));
        $this->assertNotContains(
            'PaymentRepository::update',
            array_map(static fn ($symbol): string => $symbol->id, $result->directImpact->getImpactedSymbols()),
        );

        $this->assertNotNull($result->riskFactors);
        $this->assertSame([RiskFactor::CODE_DEEP_IMPACT], array_map(
            static fn (RiskFactor $factor): string => $factor->code,
            $result->riskFactors->all(),
        ));
        $this->assertSame(2, $result->riskFactors->all()[0]->value);
        $this->assertSame(['max_depth' => 2], $result->riskFactors->all()[0]->evidence);
        $this->assertNotNull($result->riskScore);
        $this->assertSame(11, $result->riskScore->score()->value());
        $this->assertSame('low', $result->riskScore->level()->value);
        $this->assertSame([11], array_map(
            static fn ($contribution): int => $contribution->contribution,
            $result->riskScore->contributions(),
        ));
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

    public function testRunIndexesUntrackedPhpFilesWithoutTreatingThemAsChanged(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', $this->phpClass(1), 'initial');
        $repository->write('src/Example.php', $this->phpClass(2));
        $repository->write('src/Untracked.php', "<?php\nclass Untracked {}\n");

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->repositoryIndex?->phpFileCount());
        $this->assertTrue($result->repositoryIndex->hasSymbol('Untracked'));
        $this->assertCount(1, $result->changedSymbols?->changedSymbols ?? []);
        $this->assertSame('Example::run', $result->changedSymbols->changedSymbols[0]->symbol->fullyQualifiedName);
        $this->assertTrue($result->graph?->hasNode('Untracked'));
        $this->assertTrue($result->graph->getNode('Untracked')?->known);
    }

    public function testDuplicateSymbolsBecomeADomainError(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/A.php', "<?php\nclass PaymentService {}\n", 'a');
        $repository->commitFile('src/B.php', "<?php\nclass PaymentService {}\n", 'b');

        $result = (new AnalysisRunner(new GitRepository($repository->path)))->run();

        $this->assertSame('error', $result->status);
        $this->assertStringContainsString('Duplicate symbol detected:', $result->message);
        $this->assertStringContainsString('PaymentService', $result->message);
        $this->assertStringContainsString('src/A.php', $result->message);
        $this->assertStringContainsString('src/B.php', $result->message);
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
