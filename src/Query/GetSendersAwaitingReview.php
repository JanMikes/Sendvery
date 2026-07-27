<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainSendersAwaitingReview;
use Doctrine\DBAL\Connection;

/**
 * Read side for the "senders are piling up unreviewed" notification
 * (`sendvery:senders:review-reminder`) — per-domain counts plus the volume
 * figures the materiality threshold is judged on.
 *
 * `is_authorized = FALSE AND updated_at IS NULL` is how "nobody has decided
 * yet" is stored; see {@see \App\Value\SenderReviewState}.
 */
final readonly class GetSendersAwaitingReview
{
    /**
     * Named senders per domain before the email collapses into "+N more".
     */
    private const int TOP_SENDERS_PER_DOMAIN = 5;

    private const string UNREVIEWED = 'NOT ks.is_authorized AND ks.updated_at IS NULL';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Teams that have at least one unreviewed sender anywhere. A cheap prefilter
     * so the command does not run the per-domain aggregate for every team in the
     * database on every nightly run.
     *
     * @return list<string> team UUIDs
     */
    public function teamIdsWithUnreviewedSenders(): array
    {
        /** @var list<string> $teamIds */
        $teamIds = $this->database->executeQuery(
            'SELECT DISTINCT md.team_id::text
            FROM known_sender ks
            JOIN monitored_domain md ON md.id = ks.monitored_domain_id
            WHERE '.self::UNREVIEWED,
        )->fetchFirstColumn();

        return $teamIds;
    }

    /**
     * @return list<DomainSendersAwaitingReview> domains with ≥1 unreviewed
     *                                           sender, heaviest first
     */
    public function forTeam(string $teamId): array
    {
        /** @var list<array{domain_id: string, domain_name: string, needs_review_count: int|string, needs_review_messages: int|string, largest_sender_messages: int|string, distinct_name_count: int|string, domain_messages: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                md.id::text AS domain_id,
                md.domain AS domain_name,
                COUNT(*) FILTER (WHERE '.self::UNREVIEWED.') AS needs_review_count,
                COALESCE(SUM(ks.total_messages) FILTER (WHERE '.self::UNREVIEWED.'), 0) AS needs_review_messages,
                COALESCE(MAX(ks.total_messages) FILTER (WHERE '.self::UNREVIEWED.'), 0) AS largest_sender_messages,
                COUNT(DISTINCT COALESCE(ks.organization, ks.hostname, ks.source_ip)) FILTER (WHERE '.self::UNREVIEWED.') AS distinct_name_count,
                COALESCE(SUM(ks.total_messages), 0) AS domain_messages
            FROM monitored_domain md
            JOIN known_sender ks ON ks.monitored_domain_id = md.id
            WHERE md.team_id = :teamId
            GROUP BY md.id, md.domain
            HAVING COUNT(*) FILTER (WHERE '.self::UNREVIEWED.') > 0
            ORDER BY needs_review_messages DESC, md.domain',
            ['teamId' => $teamId],
        )->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $namesByDomainId = $this->topSenderNames($teamId);

        return array_map(
            static fn (array $row): DomainSendersAwaitingReview => DomainSendersAwaitingReview::fromDatabaseRow(
                $row,
                $namesByDomainId[$row['domain_id']] ?? [],
            ),
            $rows,
        );
    }

    /**
     * Top unreviewed sender names for every domain of the team in ONE query —
     * a window function rather than a per-domain LIMIT, so a team with fifty
     * domains still costs two queries in total.
     *
     * Grouped by name, not by address: a provider sending from five outbound
     * machines resolves to one organisation, and "Seznam, Seznam, Seznam" makes
     * the email look broken.
     *
     * @return array<string, list<string>> keyed by domain UUID
     */
    private function topSenderNames(string $teamId): array
    {
        /** @var list<array{domain_id: string, sender_name: string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT domain_id, sender_name
            FROM (
                SELECT
                    grouped.domain_id,
                    grouped.sender_name,
                    ROW_NUMBER() OVER (
                        PARTITION BY grouped.domain_id
                        ORDER BY grouped.messages DESC, grouped.sender_name
                    ) AS sender_rank
                FROM (
                    SELECT
                        ks.monitored_domain_id::text AS domain_id,
                        COALESCE(ks.organization, ks.hostname, ks.source_ip) AS sender_name,
                        SUM(ks.total_messages) AS messages
                    FROM known_sender ks
                    JOIN monitored_domain md ON md.id = ks.monitored_domain_id
                    WHERE md.team_id = :teamId AND '.self::UNREVIEWED.'
                    GROUP BY ks.monitored_domain_id, sender_name
                ) grouped
            ) ranked
            WHERE sender_rank <= :limit
            ORDER BY domain_id, sender_rank',
            [
                'teamId' => $teamId,
                'limit' => self::TOP_SENDERS_PER_DOMAIN,
            ],
        )->fetchAllAssociative();

        $byDomain = [];
        foreach ($rows as $row) {
            $byDomain[$row['domain_id']][] = $row['sender_name'];
        }

        return $byDomain;
    }
}
