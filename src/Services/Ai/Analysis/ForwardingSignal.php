<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * A sender whose SPF mostly fails while DKIM mostly passes — the classic
 * signature of legitimate forwarding (mailing lists, .forward rules), NOT
 * abuse. Surfaced so the explanation can reassure rather than alarm.
 * `label` is sanitized untrusted data.
 */
final readonly class ForwardingSignal
{
    public function __construct(
        public string $label,
        public int $messages,
        public float $dkimPassRate,
        public float $spfPassRate,
    ) {
    }
}
