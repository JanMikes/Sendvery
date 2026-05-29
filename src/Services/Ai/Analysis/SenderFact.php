<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * One sending source as seen in a report, with its message volume and
 * authentication rates. `label` is already sanitized (untrusted, derived from
 * resolved org / hostname / IP).
 */
final readonly class SenderFact
{
    public function __construct(
        public string $label,
        public int $messages,
        public float $dkimPassRate,
        public float $spfPassRate,
        public bool $authorized,
    ) {
    }
}
