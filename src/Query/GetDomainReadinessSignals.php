<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainReadinessResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Aggregate readiness signals for the auto-ramp evaluator, over a trailing
 * window. Team-scoped. The window defaults to 60 days so the pass-rate covers
 * the strictest tier requirement (quarantine -> reject needs 99% over 60 days);
 * using the longer window for the none -> quarantine check too only makes
 * automation MORE conservative, which is the intent.
 */
final readonly class GetDomainReadinessSignals
{
    private const int DEFAULT_WINDOW_DAYS = 60;

    public function __construct(
        private Connection $database,
    ) {
    }

    /** @param list<UuidInterface> $teamIds */
    public function forDomain(UuidInterface $domainId, array $teamIds, int $windowDays = self::DEFAULT_WINDOW_DAYS): DomainReadinessResult
    {
        if ([] === $teamIds) {
            return DomainReadinessResult::empty();
        }

        /** @var array{pass_rate: float|string|null, reports_count: int|string, message_volume: int|string, distinct_sources: int|string, authorized_failure_volume: int|string}|false $row */
        $row = $this->database->executeQuery(
            "SELECT
                COUNT(DISTINCT dr.id) AS reports_count,
                COALESCE(SUM(rec.count), 0) AS message_volume,
                COUNT(DISTINCT rec.source_ip) AS distinct_sources,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = 'pass' OR rec.spf_result = 'pass' THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0) * 100,
                    0
                ) AS pass_rate,
                COALESCE(
                    SUM(CASE WHEN ks.is_authorized = true AND rec.dkim_result <> 'pass' AND rec.spf_result <> 'pass' THEN rec.count ELSE 0 END),
                    0
                ) AS authorized_failure_volume
            FROM dmarc_report dr
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            LEFT JOIN known_sender ks ON ks.monitored_domain_id = dr.monitored_domain_id AND ks.source_ip = rec.source_ip
            WHERE md.id = :domainId
              AND md.team_id IN (:teamIds)
              AND dr.date_range_end >= NOW() - (:days || ' days')::interval",
            [
                'domainId' => $domainId->toString(),
                'teamIds' => array_map(static fn (UuidInterface $id): string => $id->toString(), $teamIds),
                'days' => $windowDays,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return DomainReadinessResult::empty();
        }

        return DomainReadinessResult::fromDatabaseRow($row);
    }
}
