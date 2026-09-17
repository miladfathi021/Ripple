<?php

declare(strict_types=1);

namespace Ripple\AI\Http;

interface AIHttpClient
{
    public function send(AIHttpRequest $request): AIHttpResponse;
}
