<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainDetailResult;
use App\Results\DomainRecentActivity;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function forDomain(string $domainId, array $teamIds): ?DomainDetailResult
    {
        if ([] === $teamIds) {
            return null;
        }


        /** @var array{domain_id: string, domain_name: string, dmarc_policy: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, first_report_at: string|null, created_at: string, total_reports: int|string, total_messages: int|string, pass_rate: float|string, unique_senders: int|string, dkim_selector: string|null}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                md.dmarc_policy AS dmarc_policy,
                md.spf_verified_at AS spf_verified_at,
                md.dkim_verified_at AS dkim_verified_at,
                md.dmarc_verified_at AS dmarc_verified_at,
                md.first_report_at AS first_report_at,
                md.created_at AS created_at,
                md.dkim_selector AS dkim_selector,
                COALESCE((SELECT COUNT(*) FROM dmarc_report dr WHERE dr.monitored_domain_id = md.id), 0) AS total_reports,
                COALESCE((
                    SELECT SUM(rec.count)
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    WHERE dr.monitored_domain_id = md.id
                ), 0) AS total_messages,
                COALESCE((
                    SELECT
                        SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                        / NULLIF(SUM(rec.count), 0)
                        * 100
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    WHERE dr.monitored_domain_id = md.id
                ), 0) AS pass_rate,
                COALESCE((
                    SELECT COUNT(DISTINCT rec.source_ip)
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    WHERE dr.monitored_domain_id = md.id
                ), 0) AS unique_senders
            FROM monitored_domain md
            WHERE md.id = :domainId AND md.team_id IN (:teamIds)',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'pass' => 'pass',
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return DomainDetailResult::fromDatabaseRow($row);
    }

    /**
     * Trailing-window snapshot (report count + pass rate) over the last
     * $days days. Used by {@see \App\Services\DmarcPolicyAdvisor} so its
     * eligibility logic compares the same population for both the
     * report-count gate and the pass-rate gate. The lifetime pass rate on
     * {@see DomainDetailResult} mixes old and recent sending posture and is
     * the wrong input for "are we ready to escalate?".
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function getRecentActivity(string $domainId, array $teamIds, int $days = 30): DomainRecentActivity
    {
        if ([] === $teamIds) {
            return DomainRecentActivity::empty();
        }

        /** @var array{reports_count: int|string, pass_rate: float|string|null}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                COUNT(DISTINCT dr.id) AS reports_count,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0)
                    * 100,
                    0
                ) AS pass_rate
            FROM dmarc_report dr
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE md.id = :domainId
              AND md.team_id IN (:teamIds)
              AND dr.date_range_end >= NOW() - (:days || \' days\')::interval',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'days' => $days,
                'pass' => 'pass',
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return DomainRecentActivity::empty();
        }

        return new DomainRecentActivity(
            reportsCount: (int) $row['reports_count'],
            passRate: (float) ($row['pass_rate'] ?? 0.0),
        );
    }
}
