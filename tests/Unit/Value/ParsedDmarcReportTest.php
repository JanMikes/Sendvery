<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\ParsedDmarcRecord;
use App\Value\ParsedDmarcReport;
use PHPUnit\Framework\TestCase;

final class ParsedDmarcReportTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $record = new ParsedDmarcRecord(
            sourceIp: '1.2.3.4',
            count: 10,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'example.com',
        );

        $report = new ParsedDmarcReport(
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            reportId: 'report-123',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'example.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Strict,
            policyP: DmarcPolicy::Reject,
            policySp: DmarcPolicy::Quarantine,
            policyPct: 100,
            records: [$record],
        );

        self::assertSame('google.com', $report->reporterOrg);
        self::assertSame('noreply@google.com', $report->reporterEmail);
        self::assertSame('report-123', $report->reportId);
        self::assertSame('example.com', $report->policyDomain);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Strict, $report->policyAspf);
        self::assertSame(DmarcPolicy::Reject, $report->policyP);
        self::assertSame(DmarcPolicy::Quarantine, $report->policySp);
        self::assertSame(100, $report->policyPct);
        self::assertCount(1, $report->records);
        self::assertSame($record, $report->records[0]);
    }

    public function testNullableSubdomainPolicy(): void
    {
        $report = new ParsedDmarcReport(
            reporterOrg: 'yahoo.com',
            reporterEmail: 'dmarc@yahoo.com',
            reportId: 'report-456',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'example.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 50,
            records: [],
        );

        self::assertNull($report->policySp);
    }
}
