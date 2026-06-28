<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Aggregate readiness signals for a domain over a trailing window, read from
 * the DMARC report data. Aligned pass-rate, message volume, distinct sending
 * IPs, report count, and the volume of mail from AUTHORIZED senders that is
 * still failing DMARC alignment (the regression signal). Age-of-data (days
 * since firstReportAt) is computed by the evaluator from the entity + clock so
 * it survives retention purge and stays deterministic in tests.
 */
final readonly class DomainReadinessResult
{
    public function __construct(
        public float $passRate,
        public int $reportsCount,
        public int $messageVolume,
        public int $distinctSources,
        public int $authorizedFailureVolume,
    ) {
    }

    public static function empty(): self
    {
        return new self(0.0, 0, 0, 0, 0);
    }

    /**
     * @param array{pass_rate: float|string|null, reports_count: int|string, message_volume: int|string, distinct_sources: int|string, authorized_failure_volume: int|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            passRate: (float) ($row['pass_rate'] ?? 0.0),
            reportsCount: (int) $row['reports_count'],
            messageVolume: (int) $row['message_volume'],
            distinctSources: (int) $row['distinct_sources'],
            authorizedFailureVolume: (int) $row['authorized_failure_volume'],
        );
    }
}
