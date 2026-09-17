<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\GitHub;

use PHPUnit\Framework\TestCase;
use Ripple\GitHub\PullRequestCommentSelector;
use Ripple\Reporting\PullRequestCommentFormatter;

final class PullRequestCommentSelectorTest extends TestCase
{
    public function testReturnsNullWhenNoRippleCommentExists(): void
    {
        $selector = new PullRequestCommentSelector();

        $this->assertNull($selector->canonicalComment([]));
        $this->assertNull($selector->canonicalComment([
            ['id' => 1, 'body' => 'Just a review note', 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 2, 'body' => null, 'created_at' => '2026-01-02T00:00:00Z'],
        ]));
    }

    public function testSelectsTheOnlyRippleComment(): void
    {
        $selector = new PullRequestCommentSelector();
        $ripple = [
            'id' => 42,
            'body' => PullRequestCommentFormatter::MARKER . "\nRisk: 10 / Low",
            'created_at' => '2026-01-03T00:00:00Z',
        ];

        $this->assertSame(
            $ripple,
            $selector->canonicalComment([
                ['id' => 1, 'body' => 'Looks good', 'created_at' => '2026-01-01T00:00:00Z'],
                $ripple,
            ]),
        );
    }

    public function testSelectsTheOldestRippleCommentWhenSeveralExist(): void
    {
        $selector = new PullRequestCommentSelector();
        $older = [
            'id' => 20,
            'body' => PullRequestCommentFormatter::MARKER . "\nfirst",
            'created_at' => '2026-01-01T10:00:00Z',
        ];
        $newer = [
            'id' => 10,
            'body' => PullRequestCommentFormatter::MARKER . "\nsecond",
            'created_at' => '2026-01-02T10:00:00Z',
        ];

        $this->assertSame(
            $older,
            $selector->canonicalComment([$newer, $older]),
        );
    }

    public function testBreaksCreatedAtTiesUsingTheLowerCommentId(): void
    {
        $selector = new PullRequestCommentSelector();
        $first = [
            'id' => 5,
            'body' => PullRequestCommentFormatter::MARKER . "\na",
            'created_at' => '2026-01-01T00:00:00Z',
        ];
        $second = [
            'id' => 9,
            'body' => PullRequestCommentFormatter::MARKER . "\nb",
            'created_at' => '2026-01-01T00:00:00Z',
        ];

        $this->assertSame($first, $selector->canonicalComment([$second, $first]));
    }

    public function testMarkerMustBeTheExactRippleHtmlComment(): void
    {
        $this->assertSame('<!-- ripple-analysis -->', PullRequestCommentSelector::MARKER);
        $this->assertSame(PullRequestCommentFormatter::MARKER, PullRequestCommentSelector::MARKER);

        $selector = new PullRequestCommentSelector();
        $this->assertNull($selector->canonicalComment([
            ['id' => 1, 'body' => '<!-- ripple analysis -->', 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 2, 'body' => 'ripple-analysis', 'created_at' => '2026-01-01T00:00:01Z'],
        ]));
    }
}
