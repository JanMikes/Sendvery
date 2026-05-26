<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\MonitoredDomain;
use App\Repository\DnsCheckResultRepository;
use App\Results\Dns\RuaScenarioResult;
use App\Services\ReportAddressProvider;
use App\Value\Dns\RuaScenario;
use App\Value\DnsCheckType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Picks the {@see RuaScenario} for a single domain using only data already on
 * hand — the latest stored DMARC check (no live DNS lookup) and the configured
 * report address. Pure given those inputs, so it doesn't need a clock or any
 * write-side dependency.
 *
 * TASK-134: the per-domain {@see self::resolveForDomainId()} stays for the
 * single-domain detail surfaces, but the dashboard overview and the ingestion
 * matrix now resolve N domains in one round trip via
 * {@see self::resolveForDomainIds()} so the hot path doesn't go N+1 as teams
 * accumulate monitored domains.
 */
readonly class RuaScenarioResolver
{
    public function __construct(
        private DnsCheckResultRepository $dnsCheckResultRepository,
        private DmarcRecordParser $parser,
        private ReportAddressProvider $reportAddressProvider,
        private Connection $database,
    ) {
    }

    public function resolveForDomain(MonitoredDomain $domain): RuaScenarioResult
    {
        return $this->resolveForDomainId($domain->id);
    }

    public function resolveForDomainId(UuidInterface $domainId): RuaScenarioResult
    {
        $check = $this->dnsCheckResultRepository->findLatestForDomainAndType($domainId, DnsCheckType::Dmarc);
        if (null === $check) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        return $this->classifyRawRecord($check->rawRecord);
    }

    /**
     * Batch variant of {@see self::resolveForDomainId()} — fetches the latest
     * stored DMARC check for every requested domain in a SINGLE SQL round trip
     * using `LEFT JOIN LATERAL ... LIMIT 1`. Pre-empts the N+1 the dashboard
     * overview controller used to issue once per `DomainOverviewResult` and the
     * ingestion matrix used to issue once per row.
     *
     * Return shape mirrors what the per-domain method would emit for each ID,
     * including a `NoRecord` entry for domains with no `dns_check_result` row
     * of `type = 'dmarc'` at all — every requested ID is guaranteed a key, so
     * callers can use it as a straight lookup map without null guards.
     *
     * @param list<string> $domainIds UUID strings; empty input returns `[]`
     *
     * @return array<string, RuaScenarioResult>
     */
    public function resolveForDomainIds(array $domainIds): array
    {
        if ([] === $domainIds) {
            return [];
        }

        // LATERAL with `LIMIT 1` per domain is the same pattern
        // `GetDomainOverview` and `GetDnsHealthOverview` use for "latest row
        // per domain" lookups. The WHERE filter on `(monitored_domain_id, type)`
        // is index-backed by `idx_dns_check_domain_type`; the ORDER BY on
        // `checked_at DESC` is NOT covered by that index, so Postgres does an
        // in-memory sort over the index-filtered candidate rows per domain.
        // That sort is cheap at realistic per-domain cardinality (handful of
        // checks per protocol) — a future `(monitored_domain_id, type,
        // checked_at DESC)` covering index would eliminate it, but that's a
        // perf-audit-driven decision, not a correctness one.
        $rows = $this->database->executeQuery(
            <<<'SQL'
                SELECT
                    md.id::text AS domain_id,
                    latest.raw_record AS raw_record
                FROM monitored_domain md
                LEFT JOIN LATERAL (
                    SELECT raw_record
                    FROM dns_check_result
                    WHERE monitored_domain_id = md.id
                      AND type = :dmarcType
                    ORDER BY checked_at DESC
                    LIMIT 1
                ) latest ON true
                WHERE md.id IN (:domainIds)
                SQL,
            [
                'domainIds' => $domainIds,
                'dmarcType' => DnsCheckType::Dmarc->value,
            ],
            [
                'domainIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        /** @var array<string, RuaScenarioResult> $resolved */
        $resolved = [];
        foreach ($rows as $row) {
            /* @var array{domain_id: string, raw_record: string|null} $row */
            $resolved[$row['domain_id']] = $this->classifyRawRecord($row['raw_record']);
        }

        // Caller may pass an ID that doesn't resolve to a `monitored_domain`
        // row (defensive against stale UUIDs reaching the resolver via the
        // dashboard's per-team scope). Backfill those with `NoRecord` so the
        // return map is total over the input — callers never have to null-check.
        foreach ($domainIds as $domainId) {
            if (!isset($resolved[$domainId])) {
                $resolved[$domainId] = new RuaScenarioResult(RuaScenario::NoRecord, null);
            }
        }

        return $resolved;
    }

    public function isSendveryAddress(string $email): bool
    {
        $lower = strtolower($email);

        if ($lower === strtolower($this->reportAddressProvider->get())) {
            return true;
        }

        $atPos = strrpos($lower, '@');
        if (false === $atPos) {
            return false;
        }

        $host = substr($lower, $atPos + 1);

        return 'sendvery.com' === $host;
    }

    /**
     * Shared classification for a `dns_check_result.raw_record` payload —
     * used both by the per-domain resolver and the batch one so both surfaces
     * stay bit-for-bit identical on every edge case (no record, no rua=,
     * mixed addresses, etc.).
     */
    private function classifyRawRecord(?string $rawRecord): RuaScenarioResult
    {
        if (null === $rawRecord) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        $parsed = $this->parser->parse($rawRecord);
        if (null === $parsed) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        if ([] === $parsed->ruaAddresses) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        $addressCount = count($parsed->ruaAddresses);

        foreach ($parsed->ruaAddresses as $address) {
            if ($this->isSendveryAddress($address)) {
                return new RuaScenarioResult(RuaScenario::PointsAtSendvery, $address, $rawRecord, $addressCount);
            }
        }

        return new RuaScenarioResult(RuaScenario::PointsAtExternal, $parsed->ruaAddresses[0], $rawRecord, $addressCount);
    }
}
