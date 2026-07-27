<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use App\Value\Dns\MxCheckResult;
use App\Value\Dns\MxRecord;
use Spatie\Dns\Dns;

final readonly class MxChecker
{
    public function __construct(
        private Dns $dns,
        private SmtpProbe $smtpProbe,
    ) {
    }

    public function check(string $domain): MxCheckResult
    {
        try {
            $dnsRecords = $this->dns->getRecords($domain, 'MX');
        } catch (\Throwable) {
            return new MxCheckResult(
                records: [],
                issues: [new DnsIssue(IssueSeverity::Critical, 'Failed to query MX records for this domain.')],
            );
        }

        $records = [];
        foreach ($dnsRecords as $dnsRecord) {
            $parsed = $this->parseMxRecord((string) $dnsRecord);
            if (null !== $parsed) {
                $records[] = $parsed;
            }
        }

        if ([] === $records) {
            return new MxCheckResult(
                records: [],
                issues: [new DnsIssue(IssueSeverity::Warning, 'No MX records found. This domain cannot receive email.', 'Add MX records pointing to your mail server.')],
            );
        }

        usort($records, static fn (MxRecord $a, MxRecord $b): int => $a->priority <=> $b->priority);

        $issues = [];

        $anyReachable = false;
        foreach ($records as $record) {
            if ($record->reachable) {
                $anyReachable = true;
            }
        }

        if (!$anyReachable) {
            // When NOT EVEN ONE server answers, the far more likely explanation
            // is that our own egress on port 25 is filtered (standard on cloud
            // hosts) — we genuinely cannot tell the difference from here. Say
            // so honestly instead of accusing the user's mail servers.
            $issues[] = new DnsIssue(IssueSeverity::Info, 'We could not reach any MX server on port 25 from our checker. This is usually a network restriction on the checking side rather than a problem with your mail servers.', 'Only investigate if you are actually seeing inbound mail delivery problems.');
        }

        $anyTlsMissing = false;
        foreach ($records as $record) {
            if ($record->reachable && false === $record->tlsSupported) {
                $anyTlsMissing = true;
            }
        }

        if ($anyTlsMissing) {
            $issues[] = new DnsIssue(IssueSeverity::Warning, 'Some MX servers do not support STARTTLS. Email may be transmitted in plaintext.', 'Enable STARTTLS on your mail servers.');
        }

        return new MxCheckResult(
            records: $records,
            issues: $issues,
        );
    }

    private function parseMxRecord(string $record): ?MxRecord
    {
        // Format: "example.com.  3600  IN  MX  10 mail.example.com."
        if (!preg_match('/MX\s+(\d+)\s+(\S+)/', $record, $matches)) {
            return null;
        }

        $priority = (int) $matches[1];
        $host = rtrim($matches[2], '.');

        $ip = $this->resolveHost($host);
        $reachable = false;
        $tlsSupported = null;

        if (null !== $ip) {
            $probeResult = $this->smtpProbe->probe($ip);
            $reachable = $probeResult->reachable;
            $tlsSupported = $probeResult->tlsSupported;
        }

        return new MxRecord(
            host: $host,
            priority: $priority,
            ip: $ip,
            reachable: $reachable,
            tlsSupported: $tlsSupported,
        );
    }

    /**
     * The mail host's address, preferring IPv4 (still what most senders reach
     * it over) and falling back to IPv6.
     *
     * The AAAA fallback is load-bearing, not a nicety: `MxCheckResult::isPassing()`
     * requires at least one record to resolve to an address, so an IPv6-only
     * mail host used to be reported as a failing MX — the same false-negative
     * class as reading MX state off a nightly snapshot. A host that publishes
     * only AAAA is perfectly deliverable; claiming its MX is broken is wrong.
     */
    private function resolveHost(string $host): ?string
    {
        return $this->resolveIpv4($host) ?? $this->resolveIpv6($host);
    }

    private function resolveIpv4(string $host): ?string
    {
        foreach ($this->recordsFor($host, 'A') as $record) {
            if (preg_match('/\bA\s+(\d+\.\d+\.\d+\.\d+)/', $record, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function resolveIpv6(string $host): ?string
    {
        foreach ($this->recordsFor($host, 'AAAA') as $record) {
            // An IPv6 literal is the only hex-and-colon token an AAAA answer
            // carries, so "at least one colon" is enough to pick it out of the
            // "<host> <ttl> IN AAAA <address>" line without re-implementing
            // RFC 4291 addressing rules.
            if (preg_match('/\bAAAA\s+([0-9A-Fa-f:]*:[0-9A-Fa-f:.]+)/', $record, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function recordsFor(string $host, string $type): array
    {
        try {
            return array_values(array_map(
                static fn (mixed $record): string => (string) $record,
                $this->dns->getRecords($host, $type),
            ));
        } catch (\Throwable) {
            return [];
        }
    }
}
