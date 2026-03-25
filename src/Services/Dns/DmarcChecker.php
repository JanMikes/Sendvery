<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DmarcCheckResult;
use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use Spatie\Dns\Dns;

final readonly class DmarcChecker
{
    public function __construct(
        private Dns $dns,
    ) {
    }

    public function check(string $domain): DmarcCheckResult
    {
        $dmarcDomain = "_dmarc.{$domain}";

        try {
            $records = $this->dns->getRecords($dmarcDomain, 'TXT');
        } catch (\Throwable) {
            return $this->noRecordResult();
        }

        $rawRecord = null;
        foreach ($records as $record) {
            $txt = $this->extractTxtValue((string) $record);
            if (str_starts_with(trim($txt), 'v=DMARC1')) {
                $rawRecord = $txt;

                break;
            }
        }

        if (null === $rawRecord) {
            return $this->noRecordResult();
        }

        $tags = $this->parseDmarcTags($rawRecord);
        $issues = [];
        $recommendations = [];

        $policy = $tags['p'] ?? null;
        $subdomainPolicy = $tags['sp'] ?? null;
        $adkim = $tags['adkim'] ?? null;
        $aspf = $tags['aspf'] ?? null;
        $pct = isset($tags['pct']) ? (int) $tags['pct'] : null;

        $ruaAddresses = $this->parseAddresses($tags['rua'] ?? '');
        $rufAddresses = $this->parseAddresses($tags['ruf'] ?? '');

        if (null === $policy) {
            $issues[] = new DnsIssue(IssueSeverity::Critical, 'DMARC record is missing the p= (policy) tag.', 'Add a policy: p=none for monitoring, p=quarantine or p=reject for enforcement.');
            $recommendations[] = 'Add a p= tag to your DMARC record.';
        } elseif ('none' === $policy) {
            $issues[] = new DnsIssue(
                IssueSeverity::Warning,
                'DMARC policy is set to "none" — monitoring only, no enforcement. Attackers can still send email as your domain.',
                'After reviewing your DMARC reports, move to p=quarantine and then p=reject.',
            );
            $recommendations[] = 'Upgrade your DMARC policy from p=none to p=quarantine or p=reject for enforcement.';
        }

        if ([] === $ruaAddresses) {
            $issues[] = new DnsIssue(
                IssueSeverity::Warning,
                'No rua= address configured. You are not receiving aggregate DMARC reports.',
                'Add rua=mailto:dmarc@yourdomain.com to receive reports.',
            );
            $recommendations[] = 'Add an rua= tag to receive DMARC aggregate reports.';
        }

        if (null !== $pct && $pct < 100) {
            $issues[] = new DnsIssue(
                IssueSeverity::Warning,
                "DMARC policy applies to only {$pct}% of messages (pct={$pct}).",
                'Set pct=100 or remove the pct tag to apply the policy to all messages.',
            );
            $recommendations[] = 'Remove the pct tag or set it to 100 to enforce the policy on all messages.';
        }

        if (null === $subdomainPolicy && null !== $policy && 'reject' !== $policy) {
            $issues[] = new DnsIssue(
                IssueSeverity::Info,
                'No subdomain policy (sp=) set. Subdomains inherit the main domain policy.',
                'Consider adding sp=reject to protect subdomains even if the main domain uses a softer policy.',
            );
        }

        return new DmarcCheckResult(
            rawRecord: $rawRecord,
            policy: $policy,
            subdomainPolicy: $subdomainPolicy,
            ruaAddresses: $ruaAddresses,
            rufAddresses: $rufAddresses,
            adkim: $adkim,
            aspf: $aspf,
            pct: $pct,
            issues: $issues,
            recommendations: $recommendations,
        );
    }

    /** @return array<string, string> */
    private function parseDmarcTags(string $record): array
    {
        $tags = [];
        $parts = explode(';', $record);

        foreach ($parts as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            $eqPos = strpos($part, '=');
            if (false === $eqPos) {
                continue;
            }
            $key = trim(substr($part, 0, $eqPos));
            $value = trim(substr($part, $eqPos + 1));
            $tags[$key] = $value;
        }

        return $tags;
    }

    /** @return array<string> */
    private function parseAddresses(string $value): array
    {
        if ('' === $value) {
            return [];
        }

        $addresses = [];
        foreach (explode(',', $value) as $addr) {
            $addr = trim($addr);
            if (str_starts_with($addr, 'mailto:')) {
                $addresses[] = substr($addr, 7);
            } elseif ('' !== $addr) {
                $addresses[] = $addr;
            }
        }

        return $addresses;
    }

    private function noRecordResult(): DmarcCheckResult
    {
        return new DmarcCheckResult(
            rawRecord: null,
            policy: null,
            subdomainPolicy: null,
            ruaAddresses: [],
            rufAddresses: [],
            adkim: null,
            aspf: null,
            pct: null,
            issues: [new DnsIssue(IssueSeverity::Critical, 'No DMARC record found for this domain.', 'Add a DMARC TXT record at _dmarc.yourdomain.com.')],
            recommendations: ['Create a DMARC record: v=DMARC1; p=none; rua=mailto:dmarc@yourdomain.com — then monitor reports and move to p=reject.'],
        );
    }

    private function extractTxtValue(string $record): string
    {
        if (preg_match('/TXT\s+"?(.+?)"?\s*$/', $record, $matches)) {
            return trim($matches[1], '"');
        }

        if (preg_match_all('/"([^"]*)"/', $record, $matches)) {
            return implode('', $matches[1]);
        }

        return $record;
    }
}
