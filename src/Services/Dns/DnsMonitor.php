<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Repository\DnsCheckResultRepository;
use App\Services\IdentityProvider;
use App\Value\Dns\DmarcSetupMode;
use App\Value\DnsCheckType;
use Psr\Clock\ClockInterface;

final readonly class DnsMonitor
{
    public function __construct(
        private SpfChecker $spfChecker,
        private DkimChecker $dkimChecker,
        private DmarcChecker $dmarcChecker,
        private MxChecker $mxChecker,
        private DmarcReportAuthorizationChecker $authorizationChecker,
        private DnsCheckResultRepository $dnsCheckResultRepository,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<DnsCheckResult>
     */
    public function check(MonitoredDomain $domain): array
    {
        $now = $this->clock->now();
        $results = [];

        $spfResult = $this->spfChecker->check($domain->domain);
        $results[] = $this->buildCheckResult($domain, DnsCheckType::Spf, $spfResult->rawRecord, $spfResult->isValid, $spfResult->issues, [
            'mechanism_count' => $spfResult->mechanismCount,
            'lookup_count' => $spfResult->lookupCount,
            'includes' => $spfResult->includes,
        ], $now);

        // TASK-146 — pass the per-domain DKIM selector preference (when set) so
        // the checker queries the user's actual selector instead of brute-forcing
        // the canonical registry. NULL preserves the existing brute-force fallback.
        $dkimResult = $this->dkimChecker->check($domain->domain, $domain->dkimSelector);
        $results[] = $this->buildCheckResult($domain, DnsCheckType::Dkim, $dkimResult->rawRecord, $dkimResult->keyExists, $dkimResult->issues, [
            'key_type' => $dkimResult->keyType,
            'key_bits' => $dkimResult->keyBits,
            'selector' => $dkimResult->selector,
            'detected_providers' => $dkimResult->detectedProviders,
            'matched_providers' => $dkimResult->matchedProviders,
        ], $now);

        $dmarcResult = $this->dmarcChecker->check($domain->domain);
        $isValidDmarc = $dmarcResult->hasRecord() && null !== $dmarcResult->policy;
        $reportAuthorizationFound = $this->authorizationChecker->check($domain->domain, $dmarcResult->rawRecord);
        $results[] = $this->buildCheckResult($domain, DnsCheckType::Dmarc, $dmarcResult->rawRecord, $isValidDmarc, $dmarcResult->issues, [
            'policy' => $dmarcResult->policy,
            'subdomain_policy' => $dmarcResult->subdomainPolicy,
            'rua_addresses' => $dmarcResult->ruaAddresses,
            'ruf_addresses' => $dmarcResult->rufAddresses,
            'adkim' => $dmarcResult->adkim,
            'aspf' => $dmarcResult->aspf,
            'pct' => $dmarcResult->pct,
            'report_authorization_found' => $reportAuthorizationFound,
            // Managed DMARC (DEC-058): the DMARC record at _dmarc.<domain> is
            // Sendvery's hosted policy, reached via the customer's CNAME. Flagged
            // so the pipeline narrates "managed by Sendvery" instead of "drift".
            'managed' => DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode,
        ], $now);

        $mxResult = $this->mxChecker->check($domain->domain);
        $results[] = $this->buildCheckResult($domain, DnsCheckType::Mx, $this->serializeMxRecords($mxResult), $mxResult->isPassing(), $mxResult->issues, [
            'records' => array_map(static fn ($r) => [
                'host' => $r->host,
                'priority' => $r->priority,
                'ip' => $r->ip,
                'reachable' => $r->reachable,
                'tls_supported' => $r->tlsSupported,
            ], $mxResult->records),
        ], $now);

        return $results;
    }

    /**
     * @param array<\App\Value\Dns\DnsIssue> $issues
     * @param array<string, mixed>           $details
     */
    private function buildCheckResult(
        MonitoredDomain $domain,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
        array $issues,
        array $details,
        \DateTimeImmutable $checkedAt,
    ): DnsCheckResult {
        $previous = $this->dnsCheckResultRepository->findLatestForDomainAndType($domain->id, $type);
        $previousRawRecord = $previous?->rawRecord;
        $hasChanged = $this->hasRecordChanged($type, $previousRawRecord, $rawRecord);
        $isFirstCheck = null === $previous;

        $serializedIssues = array_map(static fn ($issue) => [
            'severity' => $issue->severity->value,
            'message' => $issue->message,
            'recommendation' => $issue->recommendation,
        ], $issues);

        return new DnsCheckResult(
            id: $this->identityProvider->nextIdentity(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: $checkedAt,
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: $serializedIssues,
            details: $details,
            previousRawRecord: $previousRawRecord,
            hasChanged: $hasChanged,
            isFirstCheck: $isFirstCheck,
        );
    }

    /**
     * Did the record actually change, or did the answer just come back in a
     * different order?
     *
     * MX is the only check that observes a SET of records, and a DNS resolver
     * is free to return an RRset in any order — round-robin rotation between
     * equal-priority records is normal, deliberate behaviour. Comparing the
     * serialised answer string therefore reported a change on most nights for
     * any domain with more than one MX at the same priority, and users got a
     * daily "MX record changed" warning showing two identical record sets in a
     * different order. False alarms teach people to ignore the real ones.
     *
     * Canonicalising BOTH sides (rather than only what we write from now on)
     * is what stops the deploy itself firing one last bogus alert against the
     * un-canonicalised value already stored.
     */
    private function hasRecordChanged(DnsCheckType $type, ?string $previous, ?string $current): bool
    {
        if (DnsCheckType::Mx !== $type) {
            return $previous !== $current;
        }

        return $this->canonicalizeMxRecord($previous) !== $this->canonicalizeMxRecord($current);
    }

    /**
     * Order- and case-independent form of a serialised MX record set.
     *
     * Case matters as much as order: DNS hostnames are case-insensitive and
     * some resolvers echo back the case of the query (0x20 encoding), so
     * `email.webglobe.cz` and `Email.Webglobe.cz` are the same mail server and
     * must not read as an edit.
     */
    private function canonicalizeMxRecord(?string $record): ?string
    {
        if (null === $record || '' === trim($record)) {
            return $record;
        }

        $parts = array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', $record),
        );
        $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
        sort($parts, \SORT_STRING);

        return implode(', ', $parts);
    }

    private function serializeMxRecords(\App\Value\Dns\MxCheckResult $mxResult): ?string
    {
        if ([] === $mxResult->records) {
            return null;
        }

        $parts = [];
        foreach ($mxResult->records as $record) {
            $parts[] = "{$record->priority} {$record->host}";
        }

        // Sort by (priority, host) so what we store is stable across runs too.
        // MxChecker already sorts on priority, but PHP's sort is stable, which
        // means equal priorities preserve whatever order the resolver happened
        // to hand back — the very thing that has to stop varying.
        usort($parts, static function (string $a, string $b): int {
            [$priorityA, $hostA] = explode(' ', $a, 2);
            [$priorityB, $hostB] = explode(' ', $b, 2);

            return [(int) $priorityA, strtolower($hostA)] <=> [(int) $priorityB, strtolower($hostB)];
        });

        return implode(', ', $parts);
    }
}
