<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics\Laravel;

use PhpParser\Node;

final readonly class LaravelCallFact
{
    /**
     * @param list<string> $chain
     */
    public function __construct(
        public string $containingSymbol,
        public string $kind,
        public ?string $calleeClass,
        public string $name,
        public array $chain,
        public Node $node,
    ) {
    }

    public function terminal(): string
    {
        return $this->chain === [] ? $this->name : $this->chain[array_key_last($this->chain)];
    }

    public function containsMethod(string $method): bool
    {
        return in_array($method, $this->chain, true) || $this->name === $method;
    }
}
