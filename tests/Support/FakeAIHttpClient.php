<?php

declare(strict_types=1);

namespace Ripple\Tests\Support;

use Ripple\AI\AIProviderException;
use Ripple\AI\Http\AIHttpClient;
use Ripple\AI\Http\AIHttpRequest;
use Ripple\AI\Http\AIHttpResponse;

final class FakeAIHttpClient implements AIHttpClient
{
    /** @var list<AIHttpRequest> */
    public array $requests = [];

    /** @var list<AIHttpResponse|AIProviderException> */
    private array $results;

    private readonly bool $reuseSingleResult;

    /**
     * @param AIHttpResponse|AIProviderException|list<AIHttpResponse|AIProviderException> $results
     */
    public function __construct(AIHttpResponse|AIProviderException|array $results)
    {
        $this->reuseSingleResult = !is_array($results);
        $this->results = is_array($results) ? array_values($results) : [$results];
    }

    public function send(AIHttpRequest $request): AIHttpResponse
    {
        $this->requests[] = $request;
        if ($this->results === []) {
            throw new AIProviderException('AI request failed.');
        }

        $result = $this->reuseSingleResult ? $this->results[0] : array_shift($this->results);
        if ($result instanceof AIProviderException) {
            throw $result;
        }

        return $result;
    }
}
