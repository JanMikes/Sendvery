<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Entity\Team;
use App\Value\AlertSeverity;
use App\Value\WeeklyDigestAlertItem;
use App\Value\WeeklyDigestBrokenDnsItem;
use App\Value\WeeklyDigestData;
use App\Value\WeeklyDigestDomainData;
use App\Value\WeeklyDigestSenderReview;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final readonly class WeeklyDigestGenerator
{
    /**
     * How many grouped alert rows the email prints before deferring to the
     * dashboard. A digest is a summary — a dozen amber rows trains people to
     * stop reading it. Five is enough to convey "here is what's wrong" while
     * leaving the full list one click away.
     */
    public const int ATTENTION_ALERTS_LIMIT = 5;

    /**
     * New senders listed per domain before collapsing into "+N more". Keeps a
     * chatty domain from pushing the rest of the digest below the fold.
     */
    public const int NEW_SENDERS_PER_DOMAIN_LIMIT = 8;

    /**
     * Unreviewed senders named per domain before collapsing into "+N more". The
     * count is the actionable number; the names are there so the reader can
     * recognise their own mail host at a glance and know the list is not scary.
     */
    public const int UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT = 5;

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
            $previousPassRates[(string) $prev['domain']] = self::toNullableFloat($prev['pass_rate']);
        }

        $domainData = [];
        $totalMessages = 0;
        $passRateSum = 0.0;
        $domainsWithData = 0;

        foreach ($domains as $domain) {
            $domainName = (string) $domain['domain'];
            $messages = (int) $domain['total_messages'];
            $passRate = self::toNullableFloat($domain['pass_rate']);
            $totalMessages += $messages;

            if (null !== $passRate) {
                $passRateSum += $passRate;
                ++$domainsWithData;
            }

            // A delta needs real numbers on BOTH sides. Comparing against a
            // week that reported nothing would manufacture a "+94.1%" swing out
            // of thin air.
            $previousPassRate = $previousPassRates[$domainName] ?? null;
            $passRateDelta = (null !== $passRate && null !== $previousPassRate)
                ? $passRate - $previousPassRate
                : null;

            $domainId = (string) $domain['domain_id'];

            $domainData[] = new WeeklyDigestDomainData(
                domainName: $domainName,
                totalMessages: $messages,
                passRate: $passRate,
                passRateDelta: $passRateDelta,
                newSenders: $this->getNewSenders($domainId, $periodStart, $periodEnd),
                domainId: $domainId,
                senderReview: $this->getSenderReview($domainId),
            );
        }

        $alertGroups = $this->getAttentionAlertGroups($teamId, $periodStart, $periodEnd);

        return new WeeklyDigestData(
            teamName: $team->name,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            domains: $domainData,
            totalDomains: count($domains),
            totalMessages: $totalMessages,
            averagePassRate: $domainsWithData > 0 ? $passRateSum / $domainsWithData : null,
            alertsCount: array_sum(array_map(static fn (WeeklyDigestAlertItem $item): int => $item->occurrences, $alertGroups)),
            attentionAlertGroups: count($alertGroups),
            attentionAlerts: array_slice($alertGroups, 0, self::ATTENTION_ALERTS_LIMIT),
            resolvedAlertsCount: $this->countResolvedAlerts($teamId, $periodStart, $periodEnd),
            dnsChangesCount: $this->getDnsChangesCount($teamId, $periodStart, $periodEnd),
            currentlyBrokenDns: $this->getCurrentlyBrokenDns($teamId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDomainStats(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // `pass_rate` is NULL — not 0 — for a domain with no records in the
        // window. See WeeklyDigestDomainData::$passRate for why that matters.
        return $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain,
                COALESCE(SUM(rec.count), 0) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0) * 100 AS pass_rate
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
     * Senders (resolved org, else source IP) seen for this domain during the
     * window but never before it.
     *
     * "New" here means "first seen in DMARC data" and carries no opinion on
     * authorization — that lives in {@see getSenderReview()}, which reads
     * `known_sender` instead. The two are complementary and must not be merged:
     * "new this week" is an event, "still unreviewed" is a standing condition,
     * and only the second one is asking the reader to do something.
     *
     * @return list<string>
     */
    private function getNewSenders(string $domainId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<string> $senders */
        $senders = $this->database->executeQuery(
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
                )
            ORDER BY sender',
            [
                'domainId' => $domainId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchFirstColumn();

        return $senders;
    }

    /**
     * Senders on this domain that nobody has decided about yet — real
     * authorization state, not "what appeared this week". A sender discovered a
     * month ago and never reviewed keeps being reported until somebody decides
     * about it, which is the whole point of this section existing separately
     * from {@see getNewSenders()}.
     *
     * `updated_at IS NULL` is how "nobody has decided yet" is stored; see
     * {@see \App\Value\SenderReviewState} for why that column carries the fact
     * and what its one imprecision is. Deliberately NOT windowed by the digest
     * period: the question is "is this still outstanding today?", not "did it
     * become outstanding this week?".
     */
    private function getSenderReview(string $domainId): WeeklyDigestSenderReview
    {
        /** @var array{needs_review_count: int|string, needs_review_messages: int|string, distinct_name_count: int|string}|false $totals */
        $totals = $this->database->executeQuery(
            'SELECT
                COUNT(*) AS needs_review_count,
                COALESCE(SUM(ks.total_messages), 0) AS needs_review_messages,
                COUNT(DISTINCT COALESCE(ks.organization, ks.hostname, ks.source_ip)) AS distinct_name_count
            FROM known_sender ks
            WHERE ks.monitored_domain_id = :domainId
                AND ks.is_authorized = FALSE
                AND ks.updated_at IS NULL',
            ['domainId' => $domainId],
        )->fetchAssociative();

        assert(false !== $totals);

        $count = (int) $totals['needs_review_count'];

        if (0 === $count) {
            return WeeklyDigestSenderReview::none();
        }

        // Grouped, not one row per address: a provider that sends from five
        // outbound machines resolves to one organisation, and printing
        // "Seznam, Seznam, Seznam" makes the list look broken.
        /** @var list<string> $names */
        $names = $this->database->executeQuery(
            'SELECT COALESCE(ks.organization, ks.hostname, ks.source_ip) AS sender
            FROM known_sender ks
            WHERE ks.monitored_domain_id = :domainId
                AND ks.is_authorized = FALSE
                AND ks.updated_at IS NULL
            GROUP BY sender
            ORDER BY SUM(ks.total_messages) DESC, sender
            LIMIT :limit',
            [
                'domainId' => $domainId,
                'limit' => self::UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT,
            ],
        )->fetchFirstColumn();

        return new WeeklyDigestSenderReview(
            needsReviewCount: $count,
            needsReviewMessages: (int) $totals['needs_review_messages'],
            topSenderNames: $names,
            distinctNameCount: (int) $totals['distinct_name_count'],
        );
    }

    /**
     * Alerts worth a reader's attention, grouped by (domain, type) and ordered
     * most-severe-first.
     *
     * Excludes:
     *  - resolved alerts (`resolved_at IS NOT NULL`) — the problem is already
     *    fixed, so listing it as an outstanding issue is crying wolf. Counted
     *    separately by {@see countResolvedAlerts()} as good news instead.
     *  - `AlertSeverity::Success` alerts (e.g. a DNS record published for the
     *    first time) — desired outcomes, not problems.
     *
     * @return list<WeeklyDigestAlertItem>
     */
    private function getAttentionAlertGroups(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Grouped in SQL rather than PHP so the "12 new senders detected"
        // collapse costs one query no matter how chatty the week was. All rows
        // are fetched (no LIMIT) because the caller needs the true group total
        // to render "showing 5 of 11"; a team's weekly alert volume is small.
        /** @var list<array{severity: string, domain_name: string|null, occurrences: int|string, title: string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                a.severity AS severity,
                md.domain AS domain_name,
                COUNT(*) AS occurrences,
                (array_agg(a.title ORDER BY a.created_at DESC))[1] AS title
            FROM alert a
            LEFT JOIN monitored_domain md ON md.id = a.monitored_domain_id
            WHERE a.team_id = :teamId
                AND a.created_at >= :from AND a.created_at < :to
                AND a.resolved_at IS NULL
                AND a.severity <> :success
            GROUP BY a.severity, a.type, md.domain
            ORDER BY
                CASE a.severity WHEN :critical THEN 0 WHEN :warning THEN 1 ELSE 2 END,
                MAX(a.created_at) DESC',
            [
                'teamId' => $teamId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'success' => AlertSeverity::Success->value,
                'critical' => AlertSeverity::Critical->value,
                'warning' => AlertSeverity::Warning->value,
            ],
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): WeeklyDigestAlertItem => new WeeklyDigestAlertItem(
                title: $row['title'],
                severity: AlertSeverity::from($row['severity']),
                domainName: $row['domain_name'],
                occurrences: (int) $row['occurrences'],
            ),
            $rows,
        );
    }

    /**
     * Problems observed fixed during the window — the digest's one piece of
     * unambiguous good news. Keyed on `resolved_at` (not `created_at`) so a
     * long-standing issue fixed this week still counts.
     */
    private function countResolvedAlerts(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
            WHERE team_id = :teamId
                AND resolved_at >= :from AND resolved_at < :to',
            [
                'teamId' => $teamId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchOne();
    }

    private function getDnsChangesCount(string $teamId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        // Exclude per-protocol baselines so a freshly-added domain doesn't
        // inflate the digest's "DNS changes" count — same trust-erosion guard
        // as `GetDomainDnsHistory::countChanges` (TASK-125).
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE md.team_id = :teamId
                AND dcr.has_changed = true
                AND dcr.checked_at >= :from AND dcr.checked_at < :to
                AND EXISTS (
                    SELECT 1
                    FROM dns_check_result earlier
                    WHERE earlier.monitored_domain_id = dcr.monitored_domain_id
                    AND earlier.type = dcr.type
                    AND earlier.checked_at < dcr.checked_at
                )',
            [
                'teamId' => $teamId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        )->fetchOne();
    }

    /**
     * Latest dns_check_result per (domain, type) for this team where the most-recent
     * check came back invalid. Surfaces persistently-broken records in the digest so
     * the user gets a weekly nudge even when no state change fires an alert.
     *
     * @return list<WeeklyDigestBrokenDnsItem>
     */
    private function getCurrentlyBrokenDns(string $teamId): array
    {
        $rows = $this->database->executeQuery(
            'SELECT domain_name, check_type, checked_at, issues
            FROM (
                SELECT DISTINCT ON (dcr.monitored_domain_id, dcr.type)
                    md.domain AS domain_name,
                    dcr.type AS check_type,
                    dcr.checked_at AS checked_at,
                    dcr.issues AS issues,
                    dcr.is_valid AS is_valid
                FROM dns_check_result dcr
                JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
                WHERE md.team_id = :teamId
                ORDER BY dcr.monitored_domain_id, dcr.type, dcr.checked_at DESC
            ) latest
            WHERE latest.is_valid = false
            ORDER BY domain_name, check_type',
            ['teamId' => $teamId],
        )->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            /** @var list<array{severity?: string, message?: string, recommendation?: string}> $decoded */
            $decoded = is_string($row['issues']) ? json_decode($row['issues'], true) : (array) $row['issues'];
            $messages = [];
            foreach ($decoded as $issue) {
                if (isset($issue['message']) && '' !== $issue['message']) {
                    $messages[] = $issue['message'];
                }
            }

            $items[] = new WeeklyDigestBrokenDnsItem(
                domainName: (string) $row['domain_name'],
                checkType: strtoupper((string) $row['check_type']),
                checkedAt: new \DateTimeImmutable((string) $row['checked_at']),
                issueMessages: $messages,
            );
        }

        return $items;
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
