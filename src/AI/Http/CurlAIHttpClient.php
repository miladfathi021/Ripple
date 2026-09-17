<?php

declare(strict_types=1);

namespace Ripple\AI\Http;

use Ripple\AI\AIProviderException;

final class CurlAIHttpClient implements AIHttpClient
{
    public function send(AIHttpRequest $request): AIHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new AIProviderException('Ripple could not send the AI request.');
        }

        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new AIProviderException('AI request failed.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $request->timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($body === false || $errno !== 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new AIProviderException('AI request timed out.');
            }

            throw new AIProviderException('AI request failed.');
        }

        return new AIHttpResponse($statusCode, $body);
    }
}
