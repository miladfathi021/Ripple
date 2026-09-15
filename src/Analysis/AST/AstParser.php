<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

use PhpParser\Error;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final class AstParser
{
    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return list<\PhpParser\Node\Stmt>
     */
    public function parse(string $source, string $file = ''): array
    {
        try {
            $statements = $this->parser->parse($source);
        } catch (Error $error) {
            throw $this->toParseException($error, $file);
        }

        if ($statements === null) {
            throw new PhpParseException(
                $file === ''
                    ? 'Failed to parse PHP source.'
                    : sprintf('Failed to parse %s.', $file),
                $file,
            );
        }

        return $statements;
    }

    public static function supportedPhpVersion(): PhpVersion
    {
        return PhpVersion::getNewestSupported();
    }

    private function toParseException(Error $error, string $file): PhpParseException
    {
        $line = $error->getStartLine() > 0 ? $error->getStartLine() : null;
        $message = $file === ''
            ? 'Failed to parse PHP source: ' . $error->getRawMessage()
            : sprintf('Failed to parse %s: %s', $file, $error->getRawMessage());

        return new PhpParseException($message, $file, $line);
    }
}
