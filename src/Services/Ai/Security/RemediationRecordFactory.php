<?php

declare(strict_types=1);

namespace App\Services\Ai\Security;

use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\SuggestedDnsRecord;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcRuaInstruction;

/**
 * Generates the DNS records suggested alongside AI remediation guidance —
 * **in PHP, never from the model**. The remediation tool schema has no field
 * for a record value, so a prompt injection cannot smuggle a malicious TXT
 * record into the UI; the copyable records always come from here.
 *
 * Reuses {@see DmarcRuaInstruction} (the single source of truth for the
 * Sendvery `rua=` record) and the SPF baseline used by
 * {@see \App\Services\Dns\DnsRecordRecommender} so guidance stays consistent
 * across the product.
 */
final readonly class RemediationRecordFactory
{
    public function __construct(
        private ReportAddressProvider $reportAddressProvider,
    ) {
    }

    /**
     * @return list<SuggestedDnsRecord>
     */
    public function buildFor(DnsCheckFailure $failure): array
    {
        $domain = strtolower(trim($failure->domain));
        if ('' === $domain) {
            return [];
        }

        return match (strtoupper(trim($failure->recordType))) {
            'SPF' => [new SuggestedDnsRecord('TXT', $domain, 'v=spf1 -all')],
            'DMARC' => [new SuggestedDnsRecord(
                type: 'TXT',
                host: '_dmarc.'.$domain,
                value: DmarcRuaInstruction::build(null, $this->reportAddressProvider->get())->finalRecord,
            )],
            // DKIM keys are provider-generated (selector + public key) — there is
            // no deterministic value to suggest, so the narration explains where
            // to get it. MX is out of scope: Sendvery doesn't run inbound mail.
            default => [],
        };
    }
}
