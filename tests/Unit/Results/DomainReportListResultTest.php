<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainReportListResult;
use PHPUnit\Framework\TestCase;

final class DomainReportListResultTest extends TestCase
{
    public function testFromDatabaseRow(): void
    {
        $result = DomainReportListResult::fromDatabaseRow([
            'report_id' => 'rep-123',
            'reporter_org' => 'google.com',
            'date_range_begin' => '2024-04-01 00:00:00',
            'date_range_end' => '2024-04-02 00:00:00',
            'record_count' => '3',
            'pass_rate' => '98.2',
        ]);

        self::assertSame('rep-123', $result->reportId);
        self::assertSame('google.com', $result->reporterOrg);
        self::assertSame('2024-04-01 00:00:00', $result->dateRangeBegin);
        self::assertSame('2024-04-02 00:00:00', $result->dateRangeEnd);
        self::assertSame(3, $result->recordCount);
        self::assertSame(98.2, $result->passRate);
    }
}
