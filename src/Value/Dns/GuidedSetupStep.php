<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\ProtocolState;

/**
 * One step on the guided DNS setup surface: what we need, why, and — when we
 * can be precise about it — the exact record to publish presented the way a
 * DNS provider's own UI presents it (TYPE / NAME / TTL / VALUE, plus the value
 * that is there today next to the value it should become).
 *
 * All copy is baked in by {@see \App\Services\Dns\GuidedDnsSetupResolver} so the
 * Twig component stays a props-only renderer — the same convention
 * {@see \App\Value\SetupChecklistStep} documents.
 */
final readonly class GuidedSetupStep
{
    /**
     * @param string             $key          stable identifier used for test hooks + anchors ('delivery', 'spf', 'dkim', 'mx')
     * @param string             $name         short protocol label shown in the Done/Later summary rows
     * @param string             $title        imperative headline: what the user is being asked to do
     * @param string             $statusLine   one line describing where this record stands today
     * @param string             $whyText      plain-language reason the record matters
     * @param string|null        $recordType   DNS record type (TXT / CNAME / MX), null when there is no single record to name
     * @param string|null        $recordName   short host label the user types at their provider, e.g. `_dmarc`
     * @param string|null        $recordFqdn   the same host fully qualified, for providers that want the whole name
     * @param string|null        $currentValue value published today, null when nothing is there
     * @param string|null        $finalValue   value to end up with; null means we cannot hand over a literal (see $kbSlug)
     * @param list<SetupCaution> $cautions     consequences to surface under the record, never instead of it
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $title,
        public ProtocolState $state,
        public SetupTier $tier,
        public DnsRecordAction $action,
        public string $statusLine,
        public string $whyText,
        public ?string $recordType,
        public ?string $recordName,
        public ?string $recordFqdn,
        public ?int $ttl,
        public ?string $currentValue,
        public ?string $finalValue,
        public ?string $kbSlug,
        public string $healthAnchor,
        public bool $offersDeliveryChoice = false,
        public array $cautions = [],
    ) {
    }

    /**
     * Whether we can hand the user something to paste. Steps without a literal
     * (DKIM keys come from the sending platform; an over-limit SPF record needs
     * a human decision about which include to drop) render as guidance instead
     * of as a copy-me record — promising a value we cannot compute would be
     * worse than explaining the shape of the task.
     */
    public function hasCopyableRecord(): bool
    {
        return null !== $this->finalValue && '' !== trim($this->finalValue);
    }
}
