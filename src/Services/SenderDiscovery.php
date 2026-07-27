<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Turns a freshly parsed report into sender knowledge (DEC-059 WP2).
 *
 * Two things happen here, and the second one is the whole reason DEC-059 exists:
 *
 *  1. `known_sender` — the per-domain, user-owned inventory — gains or updates a
 *     row for every source IP in the report.
 *  2. `dmarc_record.resolved_hostname` / `resolved_org` are written back. Five
 *     read queries and the weekly digest select
 *     `COALESCE(resolved_org, resolved_hostname, source_ip)` from that table, and
 *     until now nothing in production ever populated those columns, so the
 *     COALESCE always degraded to a raw IP — the dashboard and the digest showed
 *     addresses where they should have shown "Seznam" (D1).
 *
 * Resolution goes through {@see SenderIdentityResolver} in one batch for the
 * whole report, so a report with fifteen addresses costs one cache read rather
 * than fifteen inline `gethostbyaddr()` calls inside a worker (D11).
 */
final readonly class SenderDiscovery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $database,
        private IdentityProvider $identityProvider,
        private SenderIdentityResolver $senderIdentityResolver,
        private ReportSenderSignals $reportSenderSignals,
    ) {
    }

    public function updateFromReport(MonitoredDomain $domain, UuidInterface $reportId): void
    {
        $periodEnd = $this->reportPeriodEnd($reportId);

        if (null === $periodEnd) {
            return;
        }

        $records = $this->aggregateBySourceIp($reportId);

        if ([] === $records) {
            return;
        }

        $sourceIps = array_keys($records);
        $existingSenders = $this->findExistingSenders($domain->id, $sourceIps);

        // The team's own verdict is part of the evidence: an address they
        // vouched for is their relay, never a suspect. It stays a *local*
        // verdict though — the resolver deliberately keeps it out of the
        // globally shared `sender_identity` row.
        $authorizedIps = [];

        foreach ($existingSenders as $sourceIp => $existing) {
            if ($existing['is_authorized']) {
                $authorizedIps[] = $sourceIp;
            }
        }

        $signalsByIp = $this->reportSenderSignals->forReport($reportId, $authorizedIps);
        $resolvedByIp = $this->senderIdentityResolver->resolveMany($sourceIps, $signalsByIp);

        foreach ($records as $sourceIp => $record) {
            $resolved = $resolvedByIp[$sourceIp];

            if ($resolved->isResolved()) {
                $this->writeEnrichmentOntoRecords($reportId, $sourceIp, $resolved->hostname, $resolved->organization);
            }

            $existing = $existingSenders[$sourceIp] ?? null;

            if (null === $existing) {
                $this->entityManager->persist(new KnownSender(
                    id: $this->identityProvider->nextIdentity(),
                    monitoredDomain: $domain,
                    sourceIp: $sourceIp,
                    firstSeenAt: $periodEnd,
                    lastSeenAt: $periodEnd,
                    totalMessages: $record['total_messages'],
                    passRate: $this->passRate($record['pass_count'], $record['total_messages']),
                    hostname: $resolved->hostname,
                    organization: $resolved->organization,
                ));

                continue;
            }

            $newTotal = $existing['total_messages'] + $record['total_messages'];
            $existingPassMessages = (int) round($existing['total_messages'] * $existing['pass_rate'] / 100);

            // LEAST/GREATEST rather than "set last_seen_at = now": reports arrive
            // out of order and days late, so an ingest must widen the window the
            // sender is known to have been active in, never redate it. Using
            // ingest time had `77.75.78.89` first seen on the day its report was
            // parsed while it had in fact been sending for three weeks (D7). It
            // also makes the activity window idempotent under
            // `sendvery:reports:reprocess`.
            $this->database->executeStatement(
                'UPDATE known_sender SET
                    first_seen_at = LEAST(first_seen_at, :periodEnd),
                    last_seen_at = GREATEST(last_seen_at, :periodEnd),
                    total_messages = :totalMessages,
                    pass_rate = :passRate,
                    hostname = COALESCE(hostname, :hostname),
                    organization = COALESCE(organization, :organization)
                WHERE id = :id',
                [
                    'id' => $existing['id'],
                    'periodEnd' => $periodEnd->format('Y-m-d H:i:s'),
                    'totalMessages' => $newTotal,
                    'passRate' => $this->passRate($existingPassMessages + $record['pass_count'], $newTotal),
                    // COALESCE, not assignment: `known_sender` is user data. Only
                    // gaps get filled — an operator's own naming of a host is
                    // never overwritten by a PTR record, and `is_authorized`,
                    // `label` and `notes` are not touched here at all.
                    'hostname' => $resolved->hostname,
                    'organization' => $resolved->organization,
                ],
            );
        }
    }

    /**
     * A reporter is allowed to describe a row that carried no messages, so the
     * zero guard is not defensive padding — without it the sender's whole
     * history would be replaced by a division-by-zero.
     */
    private function passRate(int $passed, int $total): float
    {
        return $total > 0 ? round($passed / $total * 100, 2) : 0.0;
    }

    /**
     * The report's own period, which is when the mail was actually sent.
     *
     * Null means the report disappeared between the event being recorded and
     * this handler running — a purge racing an ingest. Nothing to discover.
     */
    private function reportPeriodEnd(UuidInterface $reportId): ?\DateTimeImmutable
    {
        $periodEnd = $this->database->fetchOne(
            'SELECT date_range_end FROM dmarc_report WHERE id = :reportId',
            ['reportId' => $reportId->toString()],
        );

        if (!is_string($periodEnd)) {
            return null;
        }

        return new \DateTimeImmutable($periodEnd);
    }

    /**
     * The volumes `known_sender` keeps. The evidence used to *classify* a
     * sender is gathered by {@see ReportSenderSignals} instead — it needs the
     * per-identifier detail this aggregate deliberately collapses.
     *
     * @return array<string, array{total_messages: int, pass_count: int}> keyed by source IP
     */
    private function aggregateBySourceIp(UuidInterface $reportId): array
    {
        $rows = $this->database->executeQuery(
            'SELECT
                rec.source_ip,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END) AS pass_count
            FROM dmarc_record rec
            WHERE rec.dmarc_report_id = :reportId
            GROUP BY rec.source_ip',
            [
                'reportId' => $reportId->toString(),
                'pass' => 'pass',
            ],
        )->fetchAllAssociative();

        $aggregated = [];

        foreach ($rows as $row) {
            $aggregated[(string) $row['source_ip']] = [
                'total_messages' => (int) $row['total_messages'],
                'pass_count' => (int) $row['pass_count'],
            ];
        }

        return $aggregated;
    }

    /**
     * @param list<string> $sourceIps
     *
     * @return array<string, array{id: string, total_messages: int, pass_rate: float, is_authorized: bool}> keyed by source IP
     */
    private function findExistingSenders(UuidInterface $domainId, array $sourceIps): array
    {
        $rows = $this->database->executeQuery(
            // ::int because a raw DBAL fetch does not run Doctrine\'s boolean
            // conversion, and PostgreSQL\'s "f" would cast to true in PHP.
            'SELECT id, source_ip, total_messages, pass_rate, is_authorized::int AS is_authorized
            FROM known_sender
            WHERE monitored_domain_id = :domainId AND source_ip IN (:sourceIps)',
            [
                'domainId' => $domainId->toString(),
                'sourceIps' => $sourceIps,
            ],
            [
                'sourceIps' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $existing = [];

        foreach ($rows as $row) {
            $existing[(string) $row['source_ip']] = [
                'id' => (string) $row['id'],
                'total_messages' => (int) $row['total_messages'],
                'pass_rate' => (float) $row['pass_rate'],
                'is_authorized' => 1 === (int) $row['is_authorized'],
            ];
        }

        return $existing;
    }

    /**
     * One set-based update per distinct address rather than a per-row ORM write:
     * a report groups many records under the same sending host, and the
     * enrichment is identical for all of them.
     */
    private function writeEnrichmentOntoRecords(
        UuidInterface $reportId,
        string $sourceIp,
        ?string $hostname,
        ?string $organization,
    ): void {
        $this->database->executeStatement(
            'UPDATE dmarc_record SET resolved_hostname = :hostname, resolved_org = :organization
            WHERE dmarc_report_id = :reportId AND source_ip = :sourceIp',
            [
                'reportId' => $reportId->toString(),
                'sourceIp' => $sourceIp,
                'hostname' => $hostname,
                'organization' => $organization,
            ],
        );
    }
}
