<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\AST;

use PhpParser\Node\Stmt;
use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\AstParser;
use Ripple\Analysis\AST\PhpParseException;

final class AstParserTest extends TestCase
{
    public function testParsesValidPhpIntoStatements(): void
    {
        $statements = (new AstParser())->parse($this->fixture('simple-class.php'), 'simple-class.php');

        $this->assertNotSame([], $statements);
        $this->assertContainsOnlyInstancesOf(Stmt::class, $statements);
    }

    public function testInvalidPhpProducesAPhpParseException(): void
    {
        $parser = new AstParser();

        try {
            $parser->parse($this->fixture('syntax-error.invalid'), 'syntax-error.php');
            $this->fail('Expected PhpParseException to be thrown.');
        } catch (PhpParseException $exception) {
            $this->assertSame('syntax-error.php', $exception->sourceFile);
            $this->assertNotNull($exception->sourceLine);
            $this->assertStringContainsString('Failed to parse syntax-error.php', $exception->getMessage());
            $this->assertStringNotContainsString('Stack trace', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testParserTargetsACurrentPhpVersion(): void
    {
        $this->assertGreaterThanOrEqual(80300, AstParser::supportedPhpVersion()->id);
    }

    private function fixture(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/php/' . $name;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
