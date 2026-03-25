<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Repository\DnsCheckResultRepository;
use App\Services\IdentityProvider;
use App\Value\DnsCheckType;
use Psr\Clock\ClockInterface;

final readonly class DnsMonitor
{
    public function __construct(
        private SpfChecker $spfChecker,
        private DkimChecker $dkimChecker,
        private DmarcChecker $dmarcChecker,
        private MxChecker $mxChecker,
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

        $dkimResult = $this->dkimChecker->check($domain->domain);
        $results[] = $this->buildCheckResult($domain, DnsCheckType::Dkim, $dkimResult->rawRecord, $dkimResult->keyExists, $dkimResult->issues, [
            'key_type' => $dkimResult->keyType,
            'key_bits' => $dkimResult->keyBits,
            'selector' => $dkimResult->selector,
        ], $now);

        $dmarcResult = $this->dmarcChecker->check($domain->domain);
        $isValidDmarc = $dmarcResult->hasRecord() && null !== $dmarcResult->policy;
        $results[] = $this->buildCheckResult($domain, DnsCheckType::Dmarc, $dmarcResult->rawRecord, $isValidDmarc, $dmarcResult->issues, [
            'policy' => $dmarcResult->policy,
            'subdomain_policy' => $dmarcResult->subdomainPolicy,
            'rua_addresses' => $dmarcResult->ruaAddresses,
            'ruf_addresses' => $dmarcResult->rufAddresses,
            'adkim' => $dmarcResult->adkim,
            'aspf' => $dmarcResult->aspf,
            'pct' => $dmarcResult->pct,
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
        $hasChanged = $previousRawRecord !== $rawRecord;

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
        );
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

        return implode(', ', $parts);
    }
}
