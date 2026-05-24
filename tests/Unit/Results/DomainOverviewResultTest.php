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
            'dmarc_verified_at' => '2024-03-15 10:00:00',
            'spf_verified_at' => '2024-03-15 10:00:00',
            'dkim_verified_at' => '2024-03-15 10:00:00',
            'latest_spf_score' => '100',
            'latest_dkim_score' => '100',
            'latest_dmarc_score' => '100',
            'latest_mx_score' => '95',
        ]);

        self::assertSame('abc-123', $result->domainId);
        self::assertSame('example.com', $result->domainName);
        self::assertSame(5, $result->totalReports);
        self::assertSame('2024-04-02 00:00:00', $result->latestReportDate);
        self::assertSame(95.5, $result->passRate);
        self::assertSame('team-123', $result->teamId);
        self::assertSame('Acme Inc', $result->teamName);
        self::assertSame('2024-03-15 10:00:00', $result->dmarcVerifiedAt);
        self::assertSame('2024-03-15 10:00:00', $result->spfVerifiedAt);
        self::assertSame('2024-03-15 10:00:00', $result->dkimVerifiedAt);
        self::assertSame(100, $result->latestSpfScore);
        self::assertSame(100, $result->latestDkimScore);
        self::assertSame(100, $result->latestDmarcScore);
        self::assertSame(95, $result->latestMxScore);
    }

    public function testFromDatabaseRowWithNullSnapshotAndLatestDate(): void
    {
        $result = DomainOverviewResult::fromDatabaseRow([
            'domain_id' => 'abc-456',
            'domain_name' => 'test.com',
            'total_reports' => '0',
            'latest_report_date' => null,
            'pass_rate' => '0',
            'team_id' => 'team-456',
            'team_name' => 'Beta Corp',
            'dmarc_verified_at' => null,
            'spf_verified_at' => null,
            'dkim_verified_at' => null,
            'latest_spf_score' => null,
            'latest_dkim_score' => null,
            'latest_dmarc_score' => null,
            'latest_mx_score' => null,
        ]);

        self::assertNull($result->latestReportDate);
        self::assertSame(0, $result->totalReports);
        self::assertNull($result->dmarcVerifiedAt);
        self::assertNull($result->spfVerifiedAt);
        self::assertNull($result->dkimVerifiedAt);
        self::assertNull($result->latestSpfScore);
        self::assertNull($result->latestDkimScore);
        self::assertNull($result->latestDmarcScore);
        self::assertNull($result->latestMxScore);
    }

    public function testFromDatabaseRowSnapshotFieldsAreOptional(): void
    {
        // Backwards-compat guard: legacy callers that haven't migrated to the
        // TASK-098 LATERAL-join projection still pass rows without the
        // snapshot/verification columns. `fromDatabaseRow` falls back to null
        // so the classifier degrades to Attention (verified) / Unverified.
        $result = DomainOverviewResult::fromDatabaseRow([
            'domain_id' => 'abc-789',
            'domain_name' => 'legacy.example',
            'total_reports' => '3',
            'latest_report_date' => '2024-03-01 00:00:00',
            'pass_rate' => '88.0',
            'team_id' => 'team-789',
            'team_name' => 'Legacy Co',
            'dmarc_verified_at' => '2024-02-01 00:00:00',
        ]);

        self::assertSame('abc-789', $result->domainId);
        self::assertNull($result->spfVerifiedAt);
        self::assertNull($result->dkimVerifiedAt);
        self::assertNull($result->latestSpfScore);
        self::assertNull($result->latestDkimScore);
        self::assertNull($result->latestDmarcScore);
        self::assertNull($result->latestMxScore);
    }
}
