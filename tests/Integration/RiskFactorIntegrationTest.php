<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;
use Ripple\Analysis\Risk\RiskFactor;
use Ripple\CLI\AnalyzeCommand;
use Ripple\Git\GitRepository;
use Ripple\Reporting\ReportFormatterFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RiskFactorIntegrationTest extends TestCase
{
    public function testRiskFactorsAreDerivedFromExistingAnalysisWithoutChangingImpact(): void
    {
        $path = $this->multiFactorRepository();
        $first = (new AnalysisRunner(new GitRepository($path)))->run();
        $second = (new AnalysisRunner(new GitRepository($path)))->run();

        $this->assertTrue($first->isSuccessful());
        $this->assertNotNull($first->directImpact);
        $this->assertNotNull($first->blastRadius);
        $this->assertNotNull($first->riskFactors);

        $this->assertSame(
            ['Caller1::go', 'Caller2::go', 'Caller3::go', 'Caller4::go', 'Caller5::go'],
            array_map(static fn ($symbol): string => $symbol->id, $first->directImpact->getImpactedSymbols()),
        );
        $this->assertSame(
            ['Caller1::go', 'Caller2::go', 'Caller3::go', 'Caller4::go', 'Caller5::go', 'Leaf::work'],
            $first->blastRadius->getImpactedSymbolIds(),
        );
        $this->assertSame(2, $first->blastRadius->getEntry('Leaf::work')?->depth);
        $this->assertNull($first->blastRadius->getEntry('Extra::noop'));

        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
            ],
            array_map(static fn (RiskFactor $factor): string => $factor->code, $first->riskFactors->all()),
        );
        $this->assertSame(5, $first->riskFactors->all()[0]->value);
        $this->assertSame('Hub::run', $first->riskFactors->all()[0]->evidence['symbol']);
        $this->assertSame(6, $first->riskFactors->all()[1]->value);
        $this->assertSame(2, $first->riskFactors->all()[2]->value);
        $this->assertSame(['method_call', 'static_call'], $first->riskFactors->all()[3]->evidence['dependency_types']);
        $this->assertSame(2, $first->riskFactors->all()[4]->value);

        $this->assertNotNull($first->riskScore);
        $this->assertSame(51, $first->riskScore->score()->value());
        $this->assertSame('medium', $first->riskScore->level()->value);
        $this->assertSame(
            [15, 15, 11, 5, 5],
            array_map(static fn ($contribution): int => $contribution->contribution, $first->riskScore->contributions()),
        );
        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
            ],
            array_map(static fn ($contribution): string => $contribution->code, $first->riskScore->contributions()),
        );

        $this->assertSame(
            array_map(static fn ($symbol): string => $symbol->id, $first->directImpact->getImpactedSymbols()),
            array_map(static fn ($symbol): string => $symbol->id, $second->directImpact->getImpactedSymbols()),
        );
        $this->assertSame($first->blastRadius->getImpactedSymbolIds(), $second->blastRadius->getImpactedSymbolIds());
        $this->assertSame(
            array_map(static fn (RiskFactor $factor): array => [$factor->code, $factor->severity->value, $factor->value, $factor->evidence], $first->riskFactors->all()),
            array_map(static fn (RiskFactor $factor): array => [$factor->code, $factor->severity->value, $factor->value, $factor->evidence], $second->riskFactors->all()),
        );
        $this->assertSame($first->riskScore?->score()->value(), $second->riskScore?->score()->value());
        $this->assertSame(
            array_map(static fn ($contribution): array => [$contribution->code, $contribution->contribution], $first->riskScore->contributions()),
            array_map(static fn ($contribution): array => [$contribution->code, $contribution->contribution], $second->riskScore->contributions()),
        );
    }

    public function testCliTextAndJsonAreDeterministicAndIncludeRiskFactors(): void
    {
        $path = $this->multiFactorRepository();
        $tester = $this->commandTester($path);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $text = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'text']));
        $this->assertSame($text, $tester->getDisplay());

        $this->assertStringContainsString('Ripple Analysis', $text);
        $this->assertStringContainsString('Blast radius:', $text);
        $this->assertStringContainsString('Risk: 51 / 100 (Medium)', $text);
        $this->assertStringNotContainsString('Direct impact:', $text);
        $this->assertStringNotContainsString('Risk factors:', $text);
        $this->assertStringNotContainsString('This PR will break', $text);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $firstJson = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $this->assertSame($firstJson, $tester->getDisplay());

        $payload = json_decode($firstJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['Caller1::go', 'Caller2::go', 'Caller3::go', 'Caller4::go', 'Caller5::go'],
            array_column($payload['direct_impact'], 'impacted_symbol'),
        );
        $this->assertSame(
            ['Caller1::go', 'Caller2::go', 'Caller3::go', 'Caller4::go', 'Caller5::go', 'Leaf::work'],
            array_column($payload['blast_radius'], 'impacted_symbol'),
        );
        $this->assertSame(
            [
                RiskFactor::CODE_HIGH_FAN_IN,
                RiskFactor::CODE_LARGE_BLAST_RADIUS,
                RiskFactor::CODE_DEEP_IMPACT,
                RiskFactor::CODE_MULTIPLE_DEPENDENCY_TYPES,
                RiskFactor::CODE_MULTIPLE_CHANGED_SYMBOLS,
            ],
            array_column($payload['risk_factors'], 'code'),
        );
        $this->assertSame(5, $payload['risk_factors'][0]['value']);
        $this->assertSame(['symbol' => 'Hub::run', 'direct_dependents' => 5], $payload['risk_factors'][0]['evidence']);
        $this->assertSame(51, $payload['risk_score']['score']);
        $this->assertSame('medium', $payload['risk_score']['level']);
        $this->assertSame(
            [15, 15, 11, 5, 5],
            array_column($payload['risk_score']['contributions'], 'contribution'),
        );
        $this->assertArrayNotHasKey('score', $payload);
    }

    private function commandTester(string $workingDirectory): CommandTester
    {
        return new CommandTester(new AnalyzeCommand(
            new AnalysisRunner(new GitRepository($workingDirectory)),
            new ReportFormatterFactory(),
        ));
    }

    private function multiFactorRepository(): string
    {
        $repository = TemporaryGitRepository::create();
        foreach ($this->files() as $path => $contents) {
            $repository->commitFile($path, $contents, "add {$path}");
        }
        $repository->write('src/Hub.php', str_replace('$ok = true;', '$ok = false;', $this->files()['src/Hub.php']));
        $repository->write('src/Extra.php', str_replace('$x = 1;', '$x = 2;', $this->files()['src/Extra.php']));

        return $repository->path;
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        $files = [
            'src/Hub.php' => <<<'PHP'
<?php
class Hub
{
    public function run(): void
    {
        $ok = true;
    }
}
PHP,
            'src/Extra.php' => <<<'PHP'
<?php
class Extra
{
    public function noop(): void
    {
        $x = 1;
    }
}
PHP,
            'src/Leaf.php' => <<<'PHP'
<?php
class Leaf
{
    public function work(Caller5 $caller): void
    {
        $caller->go();
    }
}
PHP,
        ];

        $files['src/Caller1.php'] = <<<'PHP'
<?php
class Caller1
{
    public function go(): void
    {
        Hub::run();
    }
}
PHP;
        for ($i = 2; $i <= 5; $i++) {
            $files["src/Caller{$i}.php"] = <<<PHP
<?php
class Caller{$i}
{
    public function go(Hub \$hub): void
    {
        \$hub->run();
    }
}
PHP;
        }

        return $files;
    }
}
