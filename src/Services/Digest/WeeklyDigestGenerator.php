<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Entity\Team;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final readonly class WeeklyDigestGenerator
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function generate(Team $team): WeeklyDigestData
    {
        $now = $this->clock->now();
        $periodEnd = $now;
        $periodStart = $now->modify('-7 days');
        $teamId = $team->id->toString();

        $domains = $this->getDomainStats($teamId, $periodStart, $periodEnd);
        $previousDomains = $this->getDomainStats($teamId, $periodStart->modify('-7 days'), $periodStart);

        $previousPassRates = [];
        foreach ($previousDomains as $prev) {
            $previousPassRates[$prev['domain']] = (float) $prev['pass_rate'];
        }

        $domainData = [];
        $totalMessages = 0;
        $passRateSum = 0.0;
        $domainsWithData = 0;

        foreach ($domains as $domain) {
            $messages = (int) $domain['total_messages'];
            $passRate = (float) $domain['pass_rate'];
            $totalMessages += $messages;

            if ($messages > 0) {
                $passRateSum += $passRate;
                ++$domainsWithData;
            }

            $passRateDelta = null;
            if (isset($previousPassRates[$domain['domain']])) {
                $passRateDelta = $passRate - $previousPassRates[$domain['domain']];
            }

            $newSenders = $this->getNewSenders($domain['domain_id'], $periodStart, $periodEnd);
            $alerts = $this->getAlerts($teamId, $domain['domain_id'], $periodStart, $periodEnd);

            $domainData[] = new WeeklyDigestDomainData(
                domainName: $domain['domain'],
                totalMessages: $messages,
                passRate: $passRate,
                passRateDelta: $passRateDelta,
                newSenders: $newSenders,
                alerts: $alerts,
            );
        }

        $alertsCount = $this->getTotalAlertsCount($teamId, $periodStart, $periodEnd);
        $dnsChangesCount = $this->getDnsChangesCount($teamId, $periodStart, $periodEnd);

        return new WeeklyDigestData(
            teamName: $team->name,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            domains: $domainData,
            totalDomains: count($domains),
            totalMessages: $totalMessages,
            averagePassRate: $domainsWithData > 0 ? $passRateSum / $domainsWithData : 0.0,
            alertsCount: $alertsCount,
            dnsChangesCount: $dnsChangesCount,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDomainStats(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain,
                COALESCE(SUM(rec.count), 0) AS total_messages,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0) * 100,
                    0
                ) AS pass_rate
            FROM monitored_domain md
            LEFT JOIN dmarc_report dr ON dr.monitored_domain_id = md.id
                AND dr.date_range_end >= :from AND dr.date_range_end < :to
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE md.team_id = :teamId
            GROUP BY md.id, md.domain
            ORDER BY md.domain',
            [
                'teamId' => $teamId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'pass' => 'pass',
            ],
        )->fetchAllAssociative();
    }

    /**
     * @return array<string>
     */
    private function getNewSenders(string $domainId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->database->executeQuery(
            'SELECT DISTINCT COALESCE(rec.resolved_org, rec.source_ip) AS sender
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            WHERE dr.monitored_domain_id = :domainId
                AND dr.date_range_end >= :from AND dr.date_range_end < :to
                AND COALESCE(rec.resolved_org, rec.source_ip) NOT IN (
                    SELECT DISTINCT COALESCE(prev_rec.resolved_org, prev_rec.source_ip)
                    FROM dmarc_record prev_rec
                    JOIN dmarc_report prev_dr ON prev_dr.id = prev_rec.dmarc_report_id
                    WHERE prev_dr.monitored_domain_id = :domainId
                        AND prev_dr.date_range_end < :from
                )',
            [
                'domainId' => $domainId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchFirstColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAlerts(string $teamId, string $domainId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->database->executeQuery(
            'SELECT title, severity
            FROM alert
            WHERE team_id = :teamId
                AND monitored_domain_id = :domainId
                AND created_at >= :from AND created_at < :to
            ORDER BY created_at DESC',
            [
                'teamId' => $teamId,
                'domainId' => $domainId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();
    }

    private function getTotalAlertsCount(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
            WHERE team_id = :teamId
                AND created_at >= :from AND created_at < :to',
            [
                'teamId' => $teamId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchOne();
    }

    private function getDnsChangesCount(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE md.team_id = :teamId
                AND dcr.has_changed = true
                AND dcr.checked_at >= :from AND dcr.checked_at < :to',
            [
                'teamId' => $teamId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchOne();
    }
}
