<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Compact pass-rate + report-count tuple for a fixed window. Fed into
 * {@see \App\Services\PassRateRegressionAdvisor} so the advisor stays a pure
 * function over its inputs (no DB access, no time-window arithmetic) and is
 * deterministic in unit tests.
 *
 * Pass rate is expressed as percent (0.0 — 100.0). `reportCount` is the
 * raw count of {@see \App\Entity\DmarcReport} rows in the window (NOT the
 * record-count sum); the advisor uses it as a sample-size floor before
 * emitting any verdict.
 */
final readonly class PassRateAggregate
{
    public function __construct(
        public float $passRate,
        public int $reportCount,
        public int $totalMessages,
        public int $failingMessages,
    ) {
    }

    public static function empty(): self
    {
        return new self(0.0, 0, 0, 0);
    }

    /**
     * @param array{pass_rate: float|string|null, report_count: int|string, total_messages: int|string|null, failing_messages: int|string|null} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            passRate: null !== $row['pass_rate'] ? (float) $row['pass_rate'] : 0.0,
            reportCount: (int) $row['report_count'],
            totalMessages: null !== $row['total_messages'] ? (int) $row['total_messages'] : 0,
            failingMessages: null !== $row['failing_messages'] ? (int) $row['failing_messages'] : 0,
        );
    }
}
