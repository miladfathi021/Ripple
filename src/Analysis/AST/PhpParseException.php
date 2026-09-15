<?php

declare(strict_types=1);

namespace Ripple\Analysis\AST;

use RuntimeException;

final class PhpParseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $sourceFile = '',
        public readonly ?int $sourceLine = null,
    ) {
        parent::__construct($message);
    }
}
