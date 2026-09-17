<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI\OpenAI;

use PHPUnit\Framework\TestCase;
use Ripple\AI\AIProviderFactory;
use Ripple\AI\Http\AIHttpResponse;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\ApplicationFactory;
use Ripple\Git\GitRepository;
use Ripple\Tests\Support\FakeAIHttpClient;
use Ripple\Tests\Support\TemporaryGitRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeCommandOpenAITest extends TestCase
{
    private const API_KEY = 'sk-test-secret-key-do-not-leak';

    public function testAiFlagWithOpenAiProducesIndependentGeneratedSections(): void
    {
        $http = new FakeAIHttpClient([
            new AIHttpResponse(200, $this->body('This change may affect the reservation update flow.')),
            new AIHttpResponse(200, $this->body('Ripple rated this change High Risk with a score of 67.')),
            new AIHttpResponse(200, $this->body('Consider updating ReservationServiceTest::testUpdateStatus().')),
        ]);
        $tester = $this->tester($this->repositoryWithOpenAi(), $http, self::API_KEY);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertSame('This change may affect the reservation update flow.', $payload['ai_explanation']['text']);
        $this->assertSame('Ripple rated this change High Risk with a score of 67.', $payload['ai_risk_explanation']['text']);
        $this->assertSame(
            'Consider updating ReservationServiceTest::testUpdateStatus().',
            $payload['ai_test_recommendations']['text'],
        );
        $this->assertCount(3, $http->requests);
        $this->assertSame('', trim($tester->getErrorOutput()));
        $this->assertStringNotContainsString(self::API_KEY, $tester->getDisplay());
        $this->assertStringNotContainsString(self::API_KEY, $tester->getErrorOutput());
    }

    public function testAnalyzeWithoutAiFlagDoesNotSendOpenAiRequests(): void
    {
        $http = new FakeAIHttpClient(new AIHttpResponse(200, $this->body('should not appear')));
        $tester = $this->tester($this->repositoryWithOpenAi(), $http, self::API_KEY);
        $statusCode = $tester->execute(['--format' => 'json'], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame([], $http->requests);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertSame('', trim($tester->getErrorOutput()));
    }

    public function testMissingApiKeyOmitsAiSectionsAndKeepsDeterministicJson(): void
    {
        $http = new FakeAIHttpClient(new AIHttpResponse(200, $this->body('should not appear')));
        $tester = $this->tester($this->repositoryWithOpenAi(), $http, '');
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('risk_score', $payload);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertSame([], $http->requests);
        $this->assertStringContainsString('OpenAI API key is not configured.', $tester->getErrorOutput());
        $this->assertStringContainsString('Set RIPPLE_AI_API_KEY.', $tester->getErrorOutput());
        $this->assertStringNotContainsString(self::API_KEY, $tester->getErrorOutput());
        $this->assertStringNotContainsString(self::API_KEY, $tester->getDisplay());
    }

    public function testMissingModelOmitsAiSectionsWithoutHttp(): void
    {
        $repository = $this->repository();
        $repository->write('ripple.json', <<<'JSON'
{
  "ai": {
    "enabled": true,
    "provider": "openai"
  }
}
JSON);
        $http = new FakeAIHttpClient(new AIHttpResponse(200, $this->body('should not appear')));
        $tester = $this->tester($repository, $http, self::API_KEY);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertSame([], $http->requests);
        $this->assertStringContainsString('OpenAI model is not configured.', $tester->getErrorOutput());
        $this->assertStringNotContainsString(self::API_KEY, $tester->getErrorOutput());
    }

    public function testUnknownProviderOmitsAiSectionsWithoutHttp(): void
    {
        $repository = $this->repository();
        $repository->write('ripple.json', <<<'JSON'
{
  "ai": {
    "enabled": true,
    "provider": "anthropic",
    "model": "test-model"
  }
}
JSON);
        $http = new FakeAIHttpClient(new AIHttpResponse(200, $this->body('should not appear')));
        $tester = $this->tester($repository, $http, self::API_KEY);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertSame([], $http->requests);
        $this->assertStringContainsString('Unsupported AI provider "anthropic".', $tester->getErrorOutput());
    }

    public function testMissingProviderOmitsAiSectionsWithoutHttp(): void
    {
        $repository = $this->repository();
        $repository->write('ripple.json', '{"ai": {"enabled": true}}');
        $http = new FakeAIHttpClient(new AIHttpResponse(200, $this->body('should not appear')));
        $tester = $this->tester($repository, $http, self::API_KEY);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertSame([], $http->requests);
        $this->assertStringContainsString('AI is enabled but no provider is configured.', $tester->getErrorOutput());
    }

    public function testOpenAiHttpFailureKeepsDeterministicAnalysis(): void
    {
        $http = new FakeAIHttpClient(new AIHttpResponse(500, '{"error":{"message":"' . self::API_KEY . '"}}'));
        $tester = $this->tester($this->repositoryWithOpenAi(), $http, self::API_KEY);
        $statusCode = $tester->execute(['--format' => 'json', '--ai' => true], ['capture_stderr_separately' => true]);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0, $payload['risk_score']['score']);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertStringContainsString('OpenAI is unavailable.', $tester->getErrorOutput());
        $this->assertStringNotContainsString(self::API_KEY, $tester->getDisplay());
        $this->assertStringNotContainsString(self::API_KEY, $tester->getErrorOutput());
    }

    private function tester(
        TemporaryGitRepository $repository,
        FakeAIHttpClient $http,
        string $apiKey,
    ): CommandTester {
        $application = (new ApplicationFactory(
            new AnalysisRunner(new GitRepository($repository->path)),
            workingDirectory: $repository->path,
            aiProviderFactory: new AIProviderFactory($http, $apiKey),
        ))->create();

        return new CommandTester($application->find('analyze'));
    }

    private function repositoryWithOpenAi(): TemporaryGitRepository
    {
        $repository = $this->repository();
        $repository->write('ripple.json', <<<'JSON'
{
  "ai": {
    "enabled": true,
    "provider": "openai",
    "model": "test-model"
  }
}
JSON);

        return $repository;
    }

    private function repository(): TemporaryGitRepository
    {
        $repository = TemporaryGitRepository::create();
        $repository->commitFile('src/Example.php', "<?php\nclass Example {}\n", 'initial');

        return $repository;
    }

    private function body(string $text): string
    {
        return json_encode(['output_text' => $text], JSON_THROW_ON_ERROR);
    }
}
