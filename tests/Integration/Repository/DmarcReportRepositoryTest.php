<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Exceptions\DmarcReportNotFound;
use App\Repository\DmarcReportRepository;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class DmarcReportRepositoryTest extends IntegrationTestCase
{
    public function testGetReturnsReport(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repo = $this->getService(DmarcReportRepository::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Report Test',
            slug: 'report-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'report-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-get-test',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'report-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: 'test-data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);
        $em->flush();
        $em->clear();

        $found = $repo->get($reportId);
        self::assertSame($reportId->toString(), $found->id->toString());
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $repo = $this->getService(DmarcReportRepository::class);

        $this->expectException(DmarcReportNotFound::class);
        $repo->get(Uuid::uuid7());
    }

    public function testExistsByExternalIdReturnsTrue(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repo = $this->getService(DmarcReportRepository::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Exists Test',
            slug: 'exists-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'exists-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'test',
            reporterEmail: 'test@test.com',
            externalReportId: 'ext-exists-test',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'exists-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);
        $em->flush();
        $em->clear();

        self::assertTrue($repo->existsByExternalId('ext-exists-test', $domainId));
    }

    public function testExistsByExternalIdReturnsFalse(): void
    {
        $repo = $this->getService(DmarcReportRepository::class);

        self::assertFalse($repo->existsByExternalId('nonexistent', Uuid::uuid7()));
    }
}
