<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\GitHub;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AnalysisResult;
use Ripple\Git\DiffResult;
use Ripple\GitHub\PullRequestCommentSelector;
use Ripple\Reporting\PullRequestCommentFormatter;

final class RippleWorkflowTest extends TestCase
{
    public function testWorkflowUsesPullRequestAndMinimalPermissions(): void
    {
        $yaml = $this->workflow();

        $this->assertStringContainsString("on:\n  pull_request:\n", $yaml);
        $this->assertStringNotContainsString('pull_request_target', $yaml);
        $this->assertStringContainsString("permissions:\n  contents: read\n  pull-requests: write\n", $yaml);
        $this->assertStringNotContainsString('contents: write', $yaml);
        $this->assertStringNotContainsString('issues: write', $yaml);
        $this->assertStringNotContainsString('id-token: write', $yaml);
        $this->assertStringNotContainsString('secrets.', $yaml);
        $this->assertDoesNotMatchRegularExpression('/openai|anthropic|gemini|api[_-]?key/i', $yaml);
        $this->assertStringNotContainsString('--ai', $yaml);
        $this->assertStringContainsString('persist-credentials: false', $yaml);
        $this->assertStringContainsString('--network none', $yaml);
        $this->assertStringContainsString('${GITHUB_WORKSPACE}:/workspace:ro', $yaml);
        $this->assertStringNotContainsString('/var/run/docker.sock', $yaml);
        $this->assertStringNotContainsString('--privileged', $yaml);
        $this->assertStringNotContainsString('docker.sock', $yaml);
        $this->assertStringContainsString('RIPPLE_BASE_SHA: ${{ github.event.pull_request.base.sha }}', $yaml);
        $this->assertStringContainsString('git reset --mixed "$RIPPLE_BASE_SHA"', $yaml);
        $this->assertStringNotContainsString('git reset --mixed "${{ github.event.pull_request.base.sha }}"', $yaml);
    }

    public function testWorkflowReusesTheOldestRippleComment(): void
    {
        $yaml = $this->workflow();

        $this->assertStringContainsString("const marker = '" . PullRequestCommentSelector::MARKER . "';", $yaml);
        $this->assertSame('<!-- ripple-analysis -->', PullRequestCommentSelector::MARKER);
        $this->assertStringContainsString('github.paginate(github.rest.issues.listComments', $yaml);
        $this->assertStringContainsString('comment.body.includes(marker)', $yaml);
        $this->assertStringContainsString('localeCompare(String(right.created_at))', $yaml);
        $this->assertStringContainsString('Number(left.id) - Number(right.id)', $yaml);
        $this->assertStringContainsString('github.rest.issues.updateComment', $yaml);
        $this->assertStringContainsString('github.rest.issues.createComment', $yaml);
        $this->assertStringContainsString('updating the oldest', $yaml);
        $this->assertStringNotContainsString('deleteComment', $yaml);
    }

    public function testCommentFailureIsIsolatedFromAnalysis(): void
    {
        $yaml = $this->workflow();

        $this->assertStringContainsString("name: Analyze pull request", $yaml);
        $this->assertStringContainsString("name: Post pull request comment", $yaml);
        $this->assertStringContainsString('needs: analyze', $yaml);
        $this->assertStringContainsString("core.setFailed('PR comment failure:", $yaml);
        $this->assertStringContainsString('Analysis failure:', $yaml);
        $this->assertStringContainsString('Analysis result artifact is unchanged', $yaml);
        $this->assertStringContainsString('name: ripple-analysis', $yaml);
        $this->assertStringContainsString('ripple-result.json', $yaml);
        $this->assertStringContainsString('actions/upload-artifact@v4', $yaml);
        $this->assertLessThan(
            strpos($yaml, 'name: Post pull request comment') ?: PHP_INT_MAX,
            strpos($yaml, 'Upload analysis artifact') ?: 0,
        );
        $this->assertFalse(str_contains(
            substr($yaml, (int) strpos($yaml, 'name: Post pull request comment')),
            'writeFileSync',
        ));
    }

    public function testCommentFormatterKeepsTheExactMarkerAndStaysASummary(): void
    {
        $formatter = new PullRequestCommentFormatter();
        $comment = $formatter->format(new AnalysisResult(
            status: 'ok',
            diff: new DiffResult([]),
        ));

        $this->assertStringStartsWith(PullRequestCommentSelector::MARKER . "\n", $comment);
        $this->assertStringNotContainsString('Dependency graph:', $comment);
        $this->assertStringNotContainsString('Reverse dependencies:', $comment);
        $this->assertStringNotContainsString('<?php', $comment);
        $this->assertStringNotContainsString('GITHUB_TOKEN', $comment);
        $this->assertStringNotContainsString('OPENAI', $comment);
    }

    private function workflow(): string
    {
        $path = dirname(__DIR__, 3) . '/.github/workflows/ripple.yml';
        $yaml = file_get_contents($path);
        $this->assertNotFalse($yaml);

        return $yaml;
    }
}
