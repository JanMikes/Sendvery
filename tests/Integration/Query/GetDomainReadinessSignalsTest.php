<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainReadinessSignals;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class GetDomainReadinessSignalsTest extends IntegrationTestCase
{
    #[Test]
    public function aggregatesPassRateVolumeSourcesAndAuthorizedFailures(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = new GetDomainReadinessSignals($em->getConnection());

        $team = new Team(id: Uuid::uuid7(), name: 'Readiness', slug: 'readiness-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable());
        $em->persist($team);

        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable('-90 days'));
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-2 days'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        // 90 passing from an authorized sender, 10 failing from a *different*
        // authorized sender (the regression signal), 5 failing from an unknown IP.
        $em->persist($this->record($report, '1.1.1.1', 90, AuthResult::Pass, AuthResult::Pass));
        $em->persist($this->record($report, '2.2.2.2', 10, AuthResult::Fail, AuthResult::Fail));
        $em->persist($this->record($report, '3.3.3.3', 5, AuthResult::Fail, AuthResult::Fail));

        $em->persist($this->sender($domain, '1.1.1.1', isAuthorized: true));
        $em->persist($this->sender($domain, '2.2.2.2', isAuthorized: true));
        // 3.3.3.3 is not a known sender → its failures don't count as authorized failures.
        $em->flush();

        $signals = $query->forDomain($domain->id, [$team->id]);

        self::assertSame(1, $signals->reportsCount);
        self::assertSame(105, $signals->messageVolume);
        self::assertSame(3, $signals->distinctSources);
        self::assertEqualsWithDelta(90 / 105 * 100, $signals->passRate, 0.01);
        self::assertSame(10, $signals->authorizedFailureVolume);
    }

    #[Test]
    public function returnsEmptySignalsForNoTeams(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = new GetDomainReadinessSignals($em->getConnection());

        $signals = $query->forDomain(Uuid::uuid7(), []);

        self::assertSame(0, $signals->reportsCount);
        self::assertSame(0.0, $signals->passRate);
    }

    private function record(DmarcReport $report, string $ip, int $count, AuthResult $dkim, AuthResult $spf): DmarcRecord
    {
        return new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $ip,
            count: $count,
            disposition: Disposition::None,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $report->policyDomain,
        );
    }

    private function sender(MonitoredDomain $domain, string $ip, bool $isAuthorized): KnownSender
    {
        return new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $ip,
            firstSeenAt: new \DateTimeImmutable('-10 days'),
            lastSeenAt: new \DateTimeImmutable(),
            totalMessages: 100,
            passRate: 90.0,
            isAuthorized: $isAuthorized,
        );
    }
}
