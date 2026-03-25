<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DmarcRecordTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $id = Uuid::uuid7();
        $report = $this->createReport();

        $record = new DmarcRecord(
            id: $id,
            dmarcReport: $report,
            sourceIp: '209.85.220.41',
            count: 150,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'example.com',
            dkimDomain: 'example.com',
            dkimSelector: 'google',
            spfDomain: 'example.com',
            resolvedHostname: 'mail-sor-f41.google.com',
            resolvedOrg: 'Google',
        );

        self::assertSame($id, $record->id);
        self::assertSame($report, $record->dmarcReport);
        self::assertSame('209.85.220.41', $record->sourceIp);
        self::assertSame(150, $record->count);
        self::assertSame(Disposition::None, $record->disposition);
        self::assertSame(AuthResult::Pass, $record->dkimResult);
        self::assertSame(AuthResult::Pass, $record->spfResult);
        self::assertSame('example.com', $record->headerFrom);
        self::assertSame('example.com', $record->dkimDomain);
        self::assertSame('google', $record->dkimSelector);
        self::assertSame('example.com', $record->spfDomain);
        self::assertSame('mail-sor-f41.google.com', $record->resolvedHostname);
        self::assertSame('Google', $record->resolvedOrg);
    }

    public function testNullableFieldsDefaultToNull(): void
    {
        $record = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $this->createReport(),
            sourceIp: '1.2.3.4',
            count: 1,
            disposition: Disposition::Reject,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: 'test.com',
        );

        self::assertNull($record->dkimDomain);
        self::assertNull($record->dkimSelector);
        self::assertNull($record->spfDomain);
        self::assertNull($record->resolvedHostname);
        self::assertNull($record->resolvedOrg);
    }

    private function createReport(): DmarcReport
    {
        $team = new Team(id: Uuid::uuid7(), name: 'T', slug: 't', createdAt: new \DateTimeImmutable());
        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: 'test.com', createdAt: new \DateTimeImmutable());

        return new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'test',
            reporterEmail: 'test@test.com',
            externalReportId: 'ext-1',
            dateRangeBegin: new \DateTimeImmutable(),
            dateRangeEnd: new \DateTimeImmutable(),
            policyDomain: 'test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
    }
}
