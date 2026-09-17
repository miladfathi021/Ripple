<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\ApplicationFactory;
use Ripple\Git\GitRepository;
use Ripple\Tests\Support\TemporaryDirectory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ApplicationBootTest extends TestCase
{
    public function testApplicationBootsAndAnalyzeCommandSucceeds(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', "<?php\nclass Example {}\n", 'initial');

        $application = (new ApplicationFactory(
            new AnalysisRunner(new GitRepository($repository->path)),
            workingDirectory: $repository->path,
        ))->create();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $statusCode = $tester->run(['command' => 'analyze']);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('Ripple Analysis', $tester->getDisplay());
        $this->assertStringContainsString('Changed files: 0', $tester->getDisplay());
    }

    public function testHelpListsTheAnalyzeCommand(): void
    {
        $application = (new ApplicationFactory(
            workingDirectory: TemporaryDirectory::create()->path,
        ))->create();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $statusCode = $tester->run(['command' => 'help']);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('analyze', $tester->getDisplay());
    }
}
