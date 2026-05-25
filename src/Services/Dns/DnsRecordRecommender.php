<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Results\Dns\DnsRecordRecommendation;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\Dns\DnsRecordCategory;
use App\Value\DnsCheckType;

/**
 * Picks the per-category DNS recommendation surfaced on `/app/domains/{id}/health`
 * (TASK-095). Pure deterministic computation over the latest stored
 * {@see DnsCheckResult} per category — same pattern as
 * {@see \App\Services\MailboxHealthAdvisor} and
 * {@see \App\Services\SenderAuthorizationAdvisor}.
 *
 * The DMARC branch is delegated to {@see DmarcRuaInstruction::build()} so the
 * existing "add Sendvery to your rua=" rules stay the single source of truth
 * across the dashboard, the public DMARC checker, and the onboarding flow.
 *
 * The SPF branch's opening-position recommendation (`v=spf1 -all`) is
 * intentionally strict. Alternative considered: `v=spf1 ?all` (neutral). Strict
 * is the better default for a domain that has no SPF yet — nothing legitimate
 * breaks because there's nothing legitimate sending. Once real senders arrive
 * the user will adjust the record anyway.
 */
final readonly class DnsRecordRecommender
{
    /**
     * RFC 7208 ceiling for total DNS lookups in an SPF chain. Above this
     * receivers SHOULD treat the record as `permerror` — guidance must
     * surface even when the score is still "passing" because the next
     * include push you over the cliff.
     */
    private const int SPF_LOOKUP_LIMIT = 10;

    public function __construct(
        private ReportAddressProvider $reportAddressProvider,
    ) {
    }

    /**
     * @param array<value-of<DnsCheckType>, ?DnsCheckResult> $latestByType keyed by {@see DnsCheckType}::value
     *
     * @return array<value-of<DnsRecordCategory>, DnsRecordRecommendation>
     */
    public function recommendForDomain(string $domainName, array $latestByType): array
    {
        $recommendations = [];

        $spf = $latestByType[DnsCheckType::Spf->value] ?? null;
        $spfRec = $this->recommendForSpf($domainName, $spf);
        if (null !== $spfRec) {
            $recommendations[DnsRecordCategory::Spf->value] = $spfRec;
        }

        $dkim = $latestByType[DnsCheckType::Dkim->value] ?? null;
        $dkimRec = $this->recommendForDkim($domainName, $dkim);
        if (null !== $dkimRec) {
            $recommendations[DnsRecordCategory::Dkim->value] = $dkimRec;
        }

        $dmarc = $latestByType[DnsCheckType::Dmarc->value] ?? null;
        $dmarcRec = $this->recommendForDmarc($domainName, $dmarc);
        if (null !== $dmarcRec) {
            $recommendations[DnsRecordCategory::Dmarc->value] = $dmarcRec;
        }

        // MX is intentionally out of scope of "recommend a record to publish".
        // The check exists for visibility but Sendvery doesn't run the user's
        // inbound mail — recommending an MX value would be presumptuous.

        return $recommendations;
    }

    private function recommendForSpf(string $domainName, ?DnsCheckResult $spf): ?DnsRecordRecommendation
    {
        if (null === $spf || null === $spf->rawRecord || '' === trim($spf->rawRecord)) {
            return new DnsRecordRecommendation(
                category: DnsRecordCategory::Spf,
                severity: 'missing',
                recordType: 'TXT',
                recordHost: $domainName,
                recommendedValue: 'v=spf1 -all',
                whatText: 'Publish an SPF record',
                whyText: 'You have no SPF record. Even a strict reject-all baseline tells receivers "nothing should send as me" — better than silence. Adjust once you know your real senders.',
            );
        }

        $lookupCount = $this->readIntDetail($spf->details, 'lookup_count');
        if (null !== $lookupCount && $lookupCount > self::SPF_LOOKUP_LIMIT) {
            $provider = $this->guessSpfProviderFromLookups($spf->details);
            $providerHint = null !== $provider
                ? "Common candidates: `_spf.{$provider}` if you've migrated away from {$provider}."
                : 'Common candidates: an `_spf.…` include for a provider you no longer use.';

            return new DnsRecordRecommendation(
                category: DnsRecordCategory::Spf,
                severity: 'suboptimal',
                recordType: 'TXT',
                recordHost: $domainName,
                recommendedValue: null,
                whatText: 'Trim your SPF record',
                whyText: sprintf(
                    'Remove unused includes — your current record has %d lookups, the RFC 7208 max is %d. %s',
                    $lookupCount,
                    self::SPF_LOOKUP_LIMIT,
                    $providerHint,
                ),
            );
        }

        return null;
    }

    private function recommendForDkim(string $domainName, ?DnsCheckResult $dkim): ?DnsRecordRecommendation
    {
        if (null !== $dkim && $dkim->isValid) {
            return null;
        }

        return new DnsRecordRecommendation(
            category: DnsRecordCategory::Dkim,
            severity: 'missing',
            recordType: 'TXT',
            // The host isn't deterministic — DKIM keys live at
            // `<selector>._domainkey.<domain>` and the user has to read the
            // selector off their sending platform. We show the selector
            // template here so the user knows the shape, not a literal value
            // they can paste.
            recordHost: '<selector>._domainkey.'.$domainName,
            recommendedValue: null,
            whatText: 'Generate and publish a DKIM key',
            whyText: 'Generate a DKIM key in your sending platform (Gmail Workspace, Microsoft 365, Mailchimp, etc.) and publish the TXT record they give you at the selector they specify. Common selectors: `google`, `selector1`, `mxvault`.',
        );
    }

    private function recommendForDmarc(string $domainName, ?DnsCheckResult $dmarc): ?DnsRecordRecommendation
    {
        $instruction = DmarcRuaInstruction::build(
            $dmarc?->rawRecord,
            $this->reportAddressProvider->get(),
        );

        if ($instruction->alreadyConfigured) {
            return null;
        }

        $isMissing = null === $instruction->currentRecord || '' === $instruction->currentRecord;

        return new DnsRecordRecommendation(
            category: DnsRecordCategory::Dmarc,
            severity: $isMissing ? 'missing' : 'broken',
            recordType: 'TXT',
            recordHost: '_dmarc.'.$domainName,
            recommendedValue: $instruction->finalRecord,
            whatText: $isMissing ? 'Publish a DMARC record' : 'Add Sendvery to your DMARC record',
            whyText: "DMARC reports are how mail providers tell you who's sending email as your domain. This TXT record tells them to send those reports to Sendvery so we can show them to you as charts and alerts.",
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function readIntDetail(array $details, string $key): ?int
    {
        if (!array_key_exists($key, $details)) {
            return null;
        }

        $value = $details[$key];
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Cheap heuristic — if the SPF `includes` list contains a single
     * recognisable provider include, name it in the trim-this advice. We
     * deliberately don't try to be clever about multi-provider chains; the
     * fallback copy ("an include for a provider you no longer use") is
     * useful enough on its own.
     *
     * @param array<string, mixed> $details
     */
    private function guessSpfProviderFromLookups(array $details): ?string
    {
        if (!array_key_exists('includes', $details) || !is_array($details['includes'])) {
            return null;
        }

        $knownIncludes = [
            '_spf.google.com' => 'google.com',
            'spf.protection.outlook.com' => 'outlook.com',
            'spf.mailgun.org' => 'mailgun.org',
            '_spf.salesforce.com' => 'salesforce.com',
            'sendgrid.net' => 'sendgrid.net',
        ];

        foreach ($details['includes'] as $include) {
            if (!is_string($include)) {
                continue;
            }
            $normalised = strtolower(trim($include));
            if (isset($knownIncludes[$normalised])) {
                return $knownIncludes[$normalised];
            }
        }

        return null;
    }
}
