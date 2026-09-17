<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use Ripple\AI\AIProvider;
use Ripple\Analysis\AnalysisRunner;
use Ripple\CLI\ApplicationFactory;
use Ripple\Tests\Support\TemporaryGitRepository;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

final class AnalysisRemainsIndependentOfAITest extends TestCase
{
    public function testAnalysisRunnerDoesNotDependOnAnAIProvider(): void
    {
        $constructor = (new ReflectionClass(AnalysisRunner::class))->getConstructor();
        $this->assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $this->assertNotSame(AIProvider::class, $type->getName());
            $this->assertStringNotContainsString('\\AI\\', $type->getName());
        }
    }

    public function testDeterministicSourceDoesNotImportTheAILayer(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'AI' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $outerLayer = str_contains($path, DIRECTORY_SEPARATOR . 'CLI' . DIRECTORY_SEPARATOR)
                || str_contains($path, DIRECTORY_SEPARATOR . 'Reporting' . DIRECTORY_SEPARATOR);
            if (!$outerLayer) {
                $this->assertStringNotContainsString(
                    'Ripple\\AI\\',
                    $contents,
                    $path . ' must not depend on the AI layer.',
                );
            }
        $this->assertDoesNotMatchRegularExpression(
                '/OpenAI|Anthropic|Gemini|CodeCraft/',
                $contents,
                $path . ' must not mention a concrete AI vendor.',
            );
            $this->assertStringNotContainsString(
                'api.openai.com',
                $contents,
                $path . ' must not call an AI vendor.',
            );
            $this->assertStringNotContainsString(
                'codecraftapi.com',
                $contents,
                $path . ' must not call an AI vendor.',
            );
        }
    }

    public function testAnalyzeWorksWithoutAIConfiguration(): void
    {
        $payload = $this->analyzeJson(TemporaryGitRepository::create());

        $this->assertSame('ok', $payload['status']);
        $this->assertArrayNotHasKey('ai', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertArrayNotHasKey('explanation', $payload);
        $this->assertArrayHasKey('risk_score', $payload);
        $this->assertArrayHasKey('blast_radius', $payload);
    }

    public function testAnalyzeDoesNotCallAIWhenEnabledInRippleJson(): void
    {
        $repository = TemporaryGitRepository::create();
        $repository->write('ripple.json', <<<'JSON'
{
  "ai": {
    "enabled": true,
    "provider": "openai",
    "model": "test-model"
  }
}
JSON);

        $payload = $this->analyzeJson($repository);

        $this->assertSame('ok', $payload['status']);
        $this->assertArrayNotHasKey('ai', $payload);
        $this->assertArrayNotHasKey('ai_explanation', $payload);
        $this->assertArrayNotHasKey('ai_risk_explanation', $payload);
        $this->assertArrayNotHasKey('ai_test_recommendations', $payload);
        $this->assertArrayNotHasKey('explanation', $payload);
        $this->assertSame(0, $payload['risk_score']['score']);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeJson(TemporaryGitRepository $repository): array
    {
        $repository->commitFile('src/Example.php', "<?php\nclass Example {}\n", 'initial');

        $application = ApplicationFactory::forWorkingDirectory($repository->path)->create();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $statusCode = $tester->run([
            'command' => 'analyze',
            '--format' => 'json',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);

        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }
}
