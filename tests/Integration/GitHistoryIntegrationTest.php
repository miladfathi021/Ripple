<?php

declare(strict_types=1);

namespace Ripple\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ripple\CLI\ApplicationFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GitHistoryIntegrationTest extends TestCase
{
    public function testCliReportsHistoricalChurnWithoutChangingRisk(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', $this->phpClass(1), 'initial');
        $repository->commitFile('src/Example.php', $this->phpClass(2), 'second');
        $repository->write('src/Example.php', $this->phpClass(3));

        $path = $repository->path;
        $application = ApplicationFactory::forWorkingDirectory($path)->create();
        $command = $application->find('analyze');
        $tester = new CommandTester($command);

        $textStatus = $tester->execute(['--format' => 'text']);
        $this->assertSame(Command::SUCCESS, $textStatus);
        $this->assertStringContainsString('Git history:', $tester->getDisplay());
        $this->assertStringContainsString('src/Example.php', $tester->getDisplay());
        $this->assertStringContainsString('commits: 2', $tester->getDisplay());
        $this->assertStringContainsString('0/100 — Low', $tester->getDisplay());
        $this->assertStringNotContainsString('this file will break', $tester->getDisplay());

        $jsonStatus = $tester->execute(['--format' => 'json']);
        $this->assertSame(Command::SUCCESS, $jsonStatus);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('src/Example.php', $payload['churn'][0]['file']);
        $this->assertSame(2, $payload['churn'][0]['commit_count']);
        $this->assertGreaterThan(0, $payload['churn'][0]['lines_added']);
        $this->assertNotNull($payload['churn'][0]['last_changed_at']);
        $this->assertArrayNotHasKey('raw_git', $payload);
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
