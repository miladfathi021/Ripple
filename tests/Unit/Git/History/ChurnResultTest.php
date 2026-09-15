<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Git\History;

use PHPUnit\Framework\TestCase;
use Ripple\Git\History\ChurnResult;
use Ripple\Git\History\FileChurn;

final class ChurnResultTest extends TestCase
{
    public function testEmptyResultHasNoFiles(): void
    {
        $result = ChurnResult::empty();

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->files);
        $this->assertNull($result->get('src/Example.php'));
    }

    public function testFromFilesSortsByPath(): void
    {
        $newer = FileChurn::none('src/B.php');
        $older = FileChurn::none('src/A.php');

        $result = ChurnResult::fromFiles([$newer, $older]);

        $this->assertFalse($result->isEmpty());
        $this->assertSame(['src/A.php', 'src/B.php'], array_map(
            static fn (FileChurn $file): string => $file->file,
            $result->files,
        ));
        $this->assertSame($older, $result->get('src/A.php'));
    }
}
