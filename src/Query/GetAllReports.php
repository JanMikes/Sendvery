<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ReportListResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetAllReports
{
    /**
     * Aggregate expression for pass rate — extracted to a constant so the
     * SELECT projection and the optional HAVING filter share a single
     * source of truth. (Postgres can't reference SELECT aliases in HAVING,
     * so the expression is repeated rather than aliased.).
     *
     * Deliberately NULL-when-no-data, matching {@see GetDomainOverview::PASS_RATE_EXPR}:
     * `NULLIF(SUM(rec.count), 0)` makes the divisor NULL when the report has no
     * `dmarc_record` rows, and there is no `COALESCE(..., 0)` wrapper. A report
     * covering a period with no traffic is legal under RFC 7489 and storable here
     * — `ProcessDmarcReportHandler` persists the report before iterating records,
     * with no non-empty guard — and a zero would put a red "every message failed"
     * row in the list for a report that observed nothing.
     *
     * The band filters below inherit that honesty: `NULL >= 90` and `NULL <= 69.99`
     * are both NULL, so an empty report joins no band. It has no failure to
     * triage, so the "failing only" chip must not offer it as one.
     *
     * Do NOT reintroduce a zero fallback here.
     */
    private const string PASS_RATE_EXPR = 'SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
        / NULLIF(SUM(rec.count), 0)
        * 100';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string>      $teamIds      team UUIDs the caller is allowed to read from
     * @param list<string>|null $domainIds    optional explicit domain UUID multi-filter (UI multiselect)
     * @param list<string>|null $reporterOrgs optional reporter org multi-filter
     *
     * @return array<ReportListResult>
     */
    public function forTeams(
        array $teamIds,
        int $limit = 50,
        int $offset = 0,
        ?string $domainId = null,
        ?array $domainIds = null,
        ?array $reporterOrgs = null,
        ?string $passRateBand = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        ?string $search = null,
        ?string $mailboxId = null,
    ): array {
        if ([] === $teamIds) {
            return [];
        }

        $params = [
            'teamIds' => $teamIds,
            'pass' => 'pass',
            'limit' => $limit,
            'offset' => $offset,
        ];
        $types = [
            'teamIds' => ArrayParameterType::STRING,
        ];

        $whereClauses = ['md.team_id IN (:teamIds)'];

        if (null !== $domainId) {
            $whereClauses[] = 'dr.monitored_domain_id = :domainId';
            $params['domainId'] = $domainId;
        }

        if (null !== $domainIds && [] !== $domainIds) {
            $whereClauses[] = 'dr.monitored_domain_id IN (:domainIds)';
            $params['domainIds'] = $domainIds;
            $types['domainIds'] = ArrayParameterType::STRING;
        }

        if (null !== $reporterOrgs && [] !== $reporterOrgs) {
            $whereClauses[] = 'dr.reporter_org IN (:reporterOrgs)';
            $params['reporterOrgs'] = $reporterOrgs;
            $types['reporterOrgs'] = ArrayParameterType::STRING;
        }

        if (null !== $dateFrom) {
            $whereClauses[] = 'dr.date_range_end >= :dateFrom';
            $params['dateFrom'] = $dateFrom->format('Y-m-d H:i:s');
        }

        if (null !== $dateTo) {
            $whereClauses[] = 'dr.date_range_begin <= :dateTo';
            $params['dateTo'] = $dateTo->format('Y-m-d H:i:s');
        }

        if (null !== $search) {
            $whereClauses[] = '(dr.reporter_org ILIKE :search OR md.domain ILIKE :search)';
            $params['search'] = '%'.$search.'%';
        }

        // Filter reports by the inbox that pulled their underlying envelope.
        // `dmarc_report.source_envelope_id` is nullable (legacy rows pre-dating
        // the envelope intermediary, plus central-inbox reports with no team
        // mailbox) — an INNER JOIN here would silently drop those rows from
        // the unfiltered list, so we only join when the mailbox filter is set.
        if (null !== $mailboxId) {
            $whereClauses[] = 'EXISTS (
                SELECT 1 FROM received_report_email rre
                WHERE rre.id = dr.source_envelope_id
                AND rre.mailbox_connection_id = :mailboxId
            )';
            $params['mailboxId'] = $mailboxId;
        }

        $havingClauses = [];
        if ('high' === $passRateBand) {
            $havingClauses[] = self::PASS_RATE_EXPR.' >= :passRateMin';
            $params['passRateMin'] = 90.0;
        } elseif ('medium' === $passRateBand) {
            $havingClauses[] = self::PASS_RATE_EXPR.' >= :passRateMin';
            $havingClauses[] = self::PASS_RATE_EXPR.' <= :passRateMax';
            $params['passRateMin'] = 70.0;
            $params['passRateMax'] = 89.99;
        } elseif ('low' === $passRateBand) {
            $havingClauses[] = self::PASS_RATE_EXPR.' <= :passRateMax';
            $params['passRateMax'] = 69.99;
        }

        $sql = 'SELECT
                dr.id AS report_id,
                md.domain AS domain_name,
                dr.reporter_org AS reporter_org,
                dr.date_range_begin AS date_range_begin,
                dr.date_range_end AS date_range_end,
                COUNT(rec.id) AS record_count,
                '.self::PASS_RATE_EXPR.' AS pass_rate
            FROM dmarc_report dr
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE '.implode(' AND ', $whereClauses).'
            GROUP BY dr.id, md.domain, dr.reporter_org, dr.date_range_begin, dr.date_range_end';

        if ([] !== $havingClauses) {
            $sql .= ' HAVING '.implode(' AND ', $havingClauses);
        }

        // `dr.id DESC` is a tiebreaker, not decoration. Reporters send one
        // aggregate report per UTC day, so several rows sharing a
        // `date_range_end` is the norm; ordering on that column alone leaves the
        // order WITHIN a tie group unspecified, and PostgreSQL may return a
        // different one per query. Page 2 could then repeat a row from page 1 and
        // silently drop another. UUID v7 sorts by creation time, so DESC keeps the
        // "newest first" story the date column starts.
        $sql .= ' ORDER BY dr.date_range_end DESC, dr.id DESC
            LIMIT :limit OFFSET :offset';

        /** @var list<array{report_id: string, domain_name: string, reporter_org: string, date_range_begin: string, date_range_end: string, record_count: int|string, pass_rate: float|string|null}> $data */
        $data = $this->database->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(ReportListResult::fromDatabaseRow(...), $data);
    }
}
