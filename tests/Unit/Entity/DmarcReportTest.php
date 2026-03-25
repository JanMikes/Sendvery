<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DmarcReportTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $id = Uuid::uuid7();
        $team = new Team(id: Uuid::uuid7(), name: 'T', slug: 't', createdAt: new \DateTimeImmutable());
        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: 'example.com', createdAt: new \DateTimeImmutable());
        $begin = new \DateTimeImmutable('2024-04-01');
        $end = new \DateTimeImmutable('2024-04-02');
        $processedAt = new \DateTimeImmutable('2024-04-03');

        $report = new DmarcReport(
            id: $id,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-123',
            dateRangeBegin: $begin,
            dateRangeEnd: $end,
            policyDomain: 'example.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Strict,
            policyP: DmarcPolicy::Reject,
            policySp: DmarcPolicy::Quarantine,
            policyPct: 100,
            rawXml: 'compressed-data',
            processedAt: $processedAt,
        );

        self::assertSame($id, $report->id);
        self::assertSame($domain, $report->monitoredDomain);
        self::assertSame('google.com', $report->reporterOrg);
        self::assertSame('noreply@google.com', $report->reporterEmail);
        self::assertSame('ext-123', $report->externalReportId);
        self::assertSame($begin, $report->dateRangeBegin);
        self::assertSame($end, $report->dateRangeEnd);
        self::assertSame('example.com', $report->policyDomain);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Strict, $report->policyAspf);
        self::assertSame(DmarcPolicy::Reject, $report->policyP);
        self::assertSame(DmarcPolicy::Quarantine, $report->policySp);
        self::assertSame(100, $report->policyPct);
        self::assertSame('compressed-data', $report->rawXml);
        self::assertSame($processedAt, $report->processedAt);
    }

    public function testNullableSubdomainPolicy(): void
    {
        $team = new Team(id: Uuid::uuid7(), name: 'T', slug: 't', createdAt: new \DateTimeImmutable());
        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: 'test.com', createdAt: new \DateTimeImmutable());

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'yahoo.com',
            reporterEmail: 'dmarc@yahoo.com',
            externalReportId: 'ext-456',
            dateRangeBegin: new \DateTimeImmutable(),
            dateRangeEnd: new \DateTimeImmutable(),
            policyDomain: 'test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 50,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );

        self::assertNull($report->policySp);
    }

    public function testImplementsEntityWithEvents(): void
    {
        $team = new Team(id: Uuid::uuid7(), name: 'T', slug: 't', createdAt: new \DateTimeImmutable());
        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: 'test.com', createdAt: new \DateTimeImmutable());

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'test',
            reporterEmail: 'test@test.com',
            externalReportId: 'ext-789',
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

        $event = new \stdClass();
        $report->recordThat($event);
        $events = $report->popEvents();

        self::assertCount(1, $events);
        self::assertSame($event, $events[0]);
        self::assertSame([], $report->popEvents());
    }
}
