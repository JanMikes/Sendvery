<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainOverviewResult;
use PHPUnit\Framework\TestCase;

final class DomainOverviewResultTest extends TestCase
{
    public function testFromDatabaseRow(): void
    {
        $result = DomainOverviewResult::fromDatabaseRow([
            'domain_id' => 'abc-123',
            'domain_name' => 'example.com',
            'total_reports' => '5',
            'latest_report_date' => '2024-04-02 00:00:00',
            'pass_rate' => '95.5',
            'team_id' => 'team-123',
            'team_name' => 'Acme Inc',
        ]);

        self::assertSame('abc-123', $result->domainId);
        self::assertSame('example.com', $result->domainName);
        self::assertSame(5, $result->totalReports);
        self::assertSame('2024-04-02 00:00:00', $result->latestReportDate);
        self::assertSame(95.5, $result->passRate);
        self::assertSame('team-123', $result->teamId);
        self::assertSame('Acme Inc', $result->teamName);
    }

    public function testFromDatabaseRowWithNullLatestDate(): void
    {
        $result = DomainOverviewResult::fromDatabaseRow([
            'domain_id' => 'abc-456',
            'domain_name' => 'test.com',
            'total_reports' => '0',
            'latest_report_date' => null,
            'pass_rate' => '0',
            'team_id' => 'team-456',
            'team_name' => 'Beta Corp',
        ]);

        self::assertNull($result->latestReportDate);
        self::assertSame(0, $result->totalReports);
    }
}
