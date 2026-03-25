<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

final readonly class GetDomainReportData
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{
     *     domain_name: string,
     *     total_reports: int,
     *     total_messages: int,
     *     pass_rate: float,
     *     authorized_senders: int,
     *     total_senders: int,
     *     blacklisted_ips: int,
     *     latest_grade: string|null,
     *     latest_score: int|null,
     * }|null
     */
    public function forDomain(string $domainId): ?array
    {
        $domain = $this->database->executeQuery(
            'SELECT domain FROM monitored_domain WHERE id = :id',
            ['id' => $domainId],
        )->fetchOne();

        if (false === $domain) {
            return null;
        }

        $reportStats = $this->database->executeQuery(
            'SELECT
                COUNT(*) AS total_reports,
                COALESCE(SUM(rec_stats.total_messages), 0) AS total_messages,
                CASE WHEN COALESCE(SUM(rec_stats.total_messages), 0) > 0
                    THEN COALESCE(SUM(rec_stats.pass_count), 0)::float / SUM(rec_stats.total_messages) * 100
                    ELSE 0 END AS pass_rate
            FROM dmarc_report dr
            LEFT JOIN LATERAL (
                SELECT SUM(count) AS total_messages,
                       SUM(CASE WHEN dkim_result = :pass OR spf_result = :pass THEN count ELSE 0 END) AS pass_count
                FROM dmarc_record WHERE dmarc_report_id = dr.id
            ) rec_stats ON TRUE
            WHERE dr.monitored_domain_id = :domainId',
            [
                'domainId' => $domainId,
                'pass' => 'pass',
            ],
        )->fetchAssociative();

        $senderStats = $this->database->executeQuery(
            'SELECT COUNT(*) AS total_senders,
                    COUNT(*) FILTER (WHERE is_authorized) AS authorized_senders
             FROM known_sender
             WHERE monitored_domain_id = :domainId',
            ['domainId' => $domainId],
        )->fetchAssociative();

        $blacklistCount = $this->database->executeQuery(
            'SELECT COUNT(DISTINCT ip_address)
             FROM blacklist_check_result
             WHERE monitored_domain_id = :domainId AND is_listed = TRUE
             AND checked_at > NOW() - INTERVAL \'7 days\'',
            ['domainId' => $domainId],
        )->fetchOne();

        $healthSnapshot = $this->database->executeQuery(
            'SELECT grade, score FROM domain_health_snapshot
             WHERE monitored_domain_id = :domainId
             ORDER BY checked_at DESC LIMIT 1',
            ['domainId' => $domainId],
        )->fetchAssociative();

        return [
            'domain_name' => (string) $domain,
            'total_reports' => (int) ($reportStats['total_reports'] ?? 0),
            'total_messages' => (int) ($reportStats['total_messages'] ?? 0),
            'pass_rate' => round((float) ($reportStats['pass_rate'] ?? 0), 2),
            'authorized_senders' => (int) ($senderStats['authorized_senders'] ?? 0),
            'total_senders' => (int) ($senderStats['total_senders'] ?? 0),
            'blacklisted_ips' => (int) ($blacklistCount ?? 0),
            'latest_grade' => $healthSnapshot['grade'] ?? null,
            'latest_score' => null !== ($healthSnapshot['score'] ?? null) ? (int) $healthSnapshot['score'] : null,
        ];
    }
}
