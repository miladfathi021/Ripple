<?php

declare(strict_types=1);

namespace Ripple\GitHub;

use Ripple\Reporting\PullRequestCommentFormatter;

final class PullRequestCommentSelector
{
    public const MARKER = PullRequestCommentFormatter::MARKER;

    /**
     * Oldest comment that contains the exact Ripple marker, or null to create one.
     *
     * @param list<array{id: int, body?: ?string, created_at: string}> $comments
     * @return array{id: int, body?: ?string, created_at: string}|null
     */
    public function canonicalComment(array $comments): ?array
    {
        $matches = [];
        foreach ($comments as $comment) {
            $body = $comment['body'] ?? '';
            if (!is_string($body) || !str_contains($body, self::MARKER)) {
                continue;
            }

            $matches[] = $comment;
        }

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                return [$left['created_at'], $left['id']] <=> [$right['created_at'], $right['id']];
            },
        );

        return $matches[0];
    }
}
