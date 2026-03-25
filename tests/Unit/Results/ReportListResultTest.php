<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\ReportListResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportListResultTest extends TestCase
{
    #[Test]
    public function itCanBeConstructed(): void
    {
        $result = new ReportListResult(
            reportId: 'rpt-1',
            domainName: 'example.com',
            reporterOrg: 'google.com',
            dateRangeBegin: '2025-06-01',
            dateRangeEnd: '2025-06-02',
            recordCount: 5,
            passRate: 92.0,
        );

        self::assertSame('rpt-1', $result->reportId);
        self::assertSame('example.com', $result->domainName);
        self::assertSame('google.com', $result->reporterOrg);
        self::assertSame(5, $result->recordCount);
        self::assertSame(92.0, $result->passRate);
    }

    #[Test]
    public function itCanBeCreatedFromDatabaseRow(): void
    {
        $result = ReportListResult::fromDatabaseRow([
            'report_id' => 'rpt-2',
            'domain_name' => 'test.com',
            'reporter_org' => 'yahoo.com',
            'date_range_begin' => '2025-07-01',
            'date_range_end' => '2025-07-02',
            'record_count' => '3',
            'pass_rate' => '100.0',
        ]);

        self::assertSame('rpt-2', $result->reportId);
        self::assertSame('test.com', $result->domainName);
        self::assertSame('yahoo.com', $result->reporterOrg);
        self::assertSame(3, $result->recordCount);
        self::assertSame(100.0, $result->passRate);
    }
}
