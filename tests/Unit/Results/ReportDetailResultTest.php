<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\ReportDetailResult;
use App\Results\ReportRecordResult;
use PHPUnit\Framework\TestCase;

final class ReportDetailResultTest extends TestCase
{
    public function testFromDatabaseRow(): void
    {
        $recordResult = new ReportRecordResult(
            recordId: 'rec-1',
            sourceIp: '1.2.3.4',
            count: 10,
            disposition: 'none',
            dkimResult: 'pass',
            spfResult: 'pass',
            headerFrom: 'example.com',
            dkimDomain: 'example.com',
            dkimSelector: 'sel1',
            spfDomain: 'example.com',
            resolvedHostname: null,
            resolvedOrg: null,
        );

        $result = ReportDetailResult::fromDatabaseRow([
            'report_id' => 'rep-123',
            'reporter_org' => 'google.com',
            'reporter_email' => 'noreply@google.com',
            'external_report_id' => 'ext-123',
            'date_range_begin' => '2024-04-01 00:00:00',
            'date_range_end' => '2024-04-02 00:00:00',
            'policy_domain' => 'example.com',
            'policy_adkim' => 'r',
            'policy_aspf' => 'r',
            'policy_p' => 'reject',
            'policy_sp' => 'quarantine',
            'policy_pct' => '100',
            'processed_at' => '2024-04-03 10:00:00',
        ], [$recordResult]);

        self::assertSame('rep-123', $result->reportId);
        self::assertSame('google.com', $result->reporterOrg);
        self::assertSame('noreply@google.com', $result->reporterEmail);
        self::assertSame('ext-123', $result->externalReportId);
        self::assertSame('example.com', $result->policyDomain);
        self::assertSame('r', $result->policyAdkim);
        self::assertSame('r', $result->policyAspf);
        self::assertSame('reject', $result->policyP);
        self::assertSame('quarantine', $result->policySp);
        self::assertSame(100, $result->policyPct);
        self::assertCount(1, $result->records);
        self::assertSame($recordResult, $result->records[0]);
    }

    public function testNullSubdomainPolicy(): void
    {
        $result = ReportDetailResult::fromDatabaseRow([
            'report_id' => 'rep-456',
            'reporter_org' => 'yahoo.com',
            'reporter_email' => 'dmarc@yahoo.com',
            'external_report_id' => 'ext-456',
            'date_range_begin' => '2024-04-01',
            'date_range_end' => '2024-04-02',
            'policy_domain' => 'test.com',
            'policy_adkim' => 's',
            'policy_aspf' => 's',
            'policy_p' => 'none',
            'policy_sp' => null,
            'policy_pct' => '50',
            'processed_at' => '2024-04-03',
        ], []);

        self::assertNull($result->policySp);
        self::assertSame([], $result->records);
    }
}
