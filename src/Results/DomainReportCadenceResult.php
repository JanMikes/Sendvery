<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainReportCadenceResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public string $teamId,
        public \DateTimeImmutable $lastReportAt,
        public int $reportCount,
        /**
         * Median seconds between consecutive report arrivals, or null when the
         * domain has only ever received one report and no gap has been observed.
         *
         * Nullable on purpose: a single report tells us a domain CAN report but
         * says nothing about how often it should. Defaulting an unobserved
         * cadence to a number would manufacture an expectation the data does
         * not support, and callers would then measure silence against it.
         */
        public ?float $medianGapSeconds,
    ) {
    }

    /**
     * @param array{
     *     domain_id: string,
     *     domain_name: string,
     *     team_id: string,
     *     last_report_at: string,
     *     report_count: int|string,
     *     median_gap_seconds: float|string|null
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            teamId: $row['team_id'],
            lastReportAt: new \DateTimeImmutable($row['last_report_at']),
            reportCount: (int) $row['report_count'],
            medianGapSeconds: null === $row['median_gap_seconds'] ? null : (float) $row['median_gap_seconds'],
        );
    }
}
