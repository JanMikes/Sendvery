<?php

declare(strict_types=1);

namespace App\Results;

readonly final class PassRateTrendResult
{
    public function __construct(
        public string $date,
        public int $passCount,
        public int $failCount,
    ) {
    }

    /** @param array{date: string, pass_count: int, fail_count: int} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            date: $row['date'],
            passCount: (int) $row['pass_count'],
            failCount: (int) $row['fail_count'],
        );
    }
}
