<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderFactory;
use Ripple\AI\EnvironmentAIApiKey;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\ApplicationFactory;
use Ripple\CLI\EnvironmentFile;
use Ripple\Git\GitRepository;
use Ripple\Tests\Support\FakeAIHttpClient;
use Ripple\Tests\Support\TemporaryDirectory;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EnvironmentFileTest extends TestCase
{
    public function testMissingEnvFileDoesNotChangeTheEnvironment(): void
    {
        $this->withRestoredApiKey(function (): void {
            putenv(EnvironmentAIApiKey::NAME);
            $this->assertFalse(getenv(EnvironmentAIApiKey::NAME));

            EnvironmentFile::load('/ripple-missing-env-file/.env');

            $this->assertFalse(getenv(EnvironmentAIApiKey::NAME));
            $this->assertNull(EnvironmentAIApiKey::read());
        });
    }

    public function testMissingEnvFileDoesNotBreakAnalyze(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', "<?php\nclass Example {}\n", 'initial');
        $this->assertFileDoesNotExist($repository->path . '/.env');

        $application = ApplicationFactory::forWorkingDirectory($repository->path)->create();
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('analyze'));
        $statusCode = $tester->execute(['--format' => 'json']);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('risk_score', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
    }

    public function testEnvFilePopulatesAMissingApiKey(): void
    {
        $this->withRestoredApiKey(function (): void {
            putenv(EnvironmentAIApiKey::NAME);
            $directory = TemporaryDirectory::create();
            $directory->write(EnvironmentFile::FILENAME, "RIPPLE_AI_API_KEY=dotenv-test-key\n");

            EnvironmentFile::load(EnvironmentFile::pathForWorkingDirectory($directory->path));

            $this->assertSame('dotenv-test-key', getenv(EnvironmentAIApiKey::NAME));
            $this->assertSame('dotenv-test-key', EnvironmentAIApiKey::read());
        });
    }

    public function testProcessEnvironmentWinsOverEnvFile(): void
    {
        $this->withRestoredApiKey(function (): void {
            putenv(EnvironmentAIApiKey::NAME . '=environment-key');
            $directory = TemporaryDirectory::create();
            $directory->write(EnvironmentFile::FILENAME, "RIPPLE_AI_API_KEY=dotenv-key\n");

            EnvironmentFile::load(EnvironmentFile::pathForWorkingDirectory($directory->path));

            $this->assertSame('environment-key', getenv(EnvironmentAIApiKey::NAME));
            $this->assertSame('environment-key', EnvironmentAIApiKey::read());
        });
    }

    public function testQuotedValuesCommentsAndEmptyLinesAreSupported(): void
    {
        $this->withRestoredApiKey(function (): void {
            putenv(EnvironmentAIApiKey::NAME);
            putenv('RIPPLE_DOTENV_SINGLE');
            try {
                $directory = TemporaryDirectory::create();
                $directory->write(EnvironmentFile::FILENAME, <<<'ENV'
# comment

RIPPLE_AI_API_KEY="dotenv-double"
RIPPLE_DOTENV_SINGLE='dotenv-single'
ENV);

                EnvironmentFile::load(EnvironmentFile::pathForWorkingDirectory($directory->path));

                $this->assertSame('dotenv-double', getenv(EnvironmentAIApiKey::NAME));
                $this->assertSame('dotenv-single', getenv('RIPPLE_DOTENV_SINGLE'));
            } finally {
                putenv('RIPPLE_DOTENV_SINGLE');
            }
        });
    }

    public function testApplicationFactoryLoadsEnvForTheOpenAiProvider(): void
    {
        $this->withRestoredApiKey(function (): void {
            putenv(EnvironmentAIApiKey::NAME);
            $repository = TemporaryGitRepository::create();
            $repository->commitFile('src/Example.php', "<?php\nclass Example {}\n", 'initial');
            $repository->write('.env', "RIPPLE_AI_API_KEY=dotenv-test-key\n");
            $repository->write('ripple.json', <<<'JSON'
{
  "ai": {
    "enabled": true,
    "provider": "openai",
    "model": "test-model"
  }
}
JSON);
            $http = new FakeAIHttpClient(new AIHttpResponse(200, '{"output_text":"from dotenv"}'));
            $application = (new ApplicationFactory(
                new AnalysisRunner(new GitRepository($repository->path)),
                workingDirectory: $repository->path,
                aiProviderFactory: new AIProviderFactory($http),
            ))->create();
            $tester = new CommandTester($application->find('analyze'));
            $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
            $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(Command::SUCCESS, $statusCode);
            $this->assertSame('from dotenv', $payload['ai_explanation']['text']);
            $this->assertSame('Bearer dotenv-test-key', $http->requests[0]->headers['Authorization']);
            $this->assertStringNotContainsString('dotenv-test-key', $tester->getDisplay());
            $this->assertStringNotContainsString('dotenv-test-key', $tester->getErrorOutput());
        });
    }

    public function testEnvExampleExistsAndHasNoSecret(): void
    {
        $path = dirname(__DIR__, 3) . '/.env.example';
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('# API key used only for local AI analysis (OpenAI or CodeCraft).', $contents);
        $this->assertStringContainsString('# Never commit a real API key.', $contents);
        $this->assertStringContainsString('RIPPLE_AI_API_KEY=', $contents);
        $this->assertStringNotContainsString('sk-', $contents);
        $this->assertDoesNotMatchRegularExpression('/RIPPLE_AI_API_KEY=\S/', $contents);
    }

    public function testEnvIsIgnoredByGitAndExampleIsNot(): void
    {
        $root = dirname(__DIR__, 3);
        $gitignore = (string) file_get_contents($root . '/.gitignore');
        $this->assertStringContainsString(".env\n", $gitignore);
        $this->assertStringContainsString("!.env.example\n", $gitignore);

        $ignored = $this->gitCheckIgnore($root, '.env');
        $this->assertSame(0, $ignored['exitCode']);
        $this->assertSame('.env', $this->gitIgnorePattern($ignored['stdout']));

        $example = $this->gitCheckIgnore($root, '.env.example');
        $this->assertSame('!.env.example', $this->gitIgnorePattern($example['stdout']));
    }

    /**
     * @param callable(): void $callback
     */
    private function withRestoredApiKey(callable $callback): void
    {
        $previous = getenv(EnvironmentAIApiKey::NAME);
        try {
            $callback();
        } finally {
            if (is_string($previous)) {
                putenv(EnvironmentAIApiKey::NAME . '=' . $previous);
            } else {
                putenv(EnvironmentAIApiKey::NAME);
            }
        }
    }

    /**
     * @return array{exitCode: int, stdout: string}
     */
    private function gitCheckIgnore(string $root, string $path): array
    {
        $pipes = [];
        $process = proc_open(
            ['git', 'check-ignore', '-v', '--', $path],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
        ];
    }

    private function gitIgnorePattern(string $verboseOutput): string
    {
        $line = trim($verboseOutput);
        $tab = strpos($line, "\t");
        if ($tab !== false) {
            $line = substr($line, 0, $tab);
        }

        $parts = explode(':', $line, 3);

        return $parts[2] ?? '';
    }
}
