<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * An unrecognized source using the protected domain in its From address while
 * failing both SPF and DKIM — a possible spoofing / phishing attempt. When
 * `delivered` is true the messages slipped through (disposition=none), which is
 * the urgent case. `label` is sanitized untrusted data (resolved org or IP).
 */
final readonly class SpoofingSignal
{
    public function __construct(
        public string $label,
        public int $messages,
        public bool $delivered,
    ) {
    }
}
