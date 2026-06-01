<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Builds a Guzzle "map response" middleware (for Http::globalResponseMiddleware)
 * that recovers the provider's billed cost which laravel/ai discards. It triggers
 * on the OpenRouter-shaped `usage.cost` field — Laravel's response middleware does
 * not expose the request URL, so we match by this precise body shape rather than by
 * host. ONLY the usage/cost block + response id are captured; never message content.
 * The body stream is rewound so the response stays fully consumable downstream.
 */
class HttpUsageCaptureMiddleware
{
    /**
     * @return callable(ResponseInterface): ResponseInterface
     */
    public static function make(RawResponseCapture $capture, bool $storeRaw = false): callable
    {
        return static function (ResponseInterface $response) use ($capture, $storeRaw): ResponseInterface {
            try {
                $body = (string) $response->getBody();
                if ($response->getBody()->isSeekable()) {
                    $response->getBody()->rewind();
                }

                $data = json_decode($body, true);
                $usage = is_array($data) ? ($data['usage'] ?? null) : null;

                if (is_array($usage) && isset($usage['cost']) && is_numeric($usage['cost'])) {
                    $promptDetails = $usage['prompt_tokens_details'] ?? [];
                    $completionDetails = $usage['completion_tokens_details'] ?? [];

                    $capture->push(array_filter([
                        'id' => is_array($data) ? ($data['id'] ?? null) : null,
                        'cost' => (float) $usage['cost'],
                        'currency' => 'credits',
                        'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                        'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                        'cached_tokens' => (int) ($promptDetails['cached_tokens'] ?? 0),
                        'reasoning_tokens' => (int) ($completionDetails['reasoning_tokens'] ?? 0),
                        'usage_raw' => $storeRaw ? $usage : null,
                    ], static fn ($v) => $v !== null));
                }
            } catch (Throwable) {
                // Capture is best-effort; never break the HTTP pipeline.
            }

            return $response;
        };
    }
}
