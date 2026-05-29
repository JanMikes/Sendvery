<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised by `AnthropicClient` when a Messages API call fails or returns an
 * unusable response. `retryable` mirrors Anthropic's guidance: 429/500/529 are
 * transient (let Messenger retry / land in the `failed` transport), while 4xx
 * (bad request, auth, not found) and malformed responses are permanent.
 */
final class AnthropicRequestFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
