<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeOldDmarcReportsCommandTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testPurgesReportsBeyondPlanRetentionPerTeam(): void
    {
        // Free plan = 30 days retention.
        $freeTeam = $this->createTeam(SubscriptionPlan::Free);
        $freeDomain = $this->createDomain($freeTeam, 'free.example.com');
        $oldFreeReport = $this->createReport($freeDomain, processedAt: new \DateTimeImmutable('-100 days'));
        $recentFreeReport = $this->createReport($freeDomain, processedAt: new \DateTimeImmutable('-3 days'));

        // Business plan = unlimited retention.
        $businessTeam = $this->createTeam(SubscriptionPlan::Business);
        $businessDomain = $this->createDomain($businessTeam, 'biz.example.com');
        $veryOldBizReport = $this->createReport($businessDomain, processedAt: new \DateTimeImmutable('-2 years'));

        $this->em->flush();

        $exit = $this->tester()->execute([]);

        self::assertSame(0, $exit);
        $this->em->clear();

        self::assertNull(
            $this->em->find(DmarcReport::class, $oldFreeReport->id),
            'Free plan keeps 30 days; 100-day-old report should be gone.',
        );
        self::assertNotNull(
            $this->em->find(DmarcReport::class, $recentFreeReport->id),
            'Recent free-plan report stays.',
        );
        self::assertNotNull(
            $this->em->find(DmarcReport::class, $veryOldBizReport->id),
            'Business plan has unlimited retention — nothing should be purged.',
        );
    }

    public function testReportsZeroWhenNothingToPurge(): void
    {
        $team = $this->createTeam(SubscriptionPlan::Personal);
        $domain = $this->createDomain($team, 'personal.example.com');
        $this->createReport($domain, processedAt: new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No DMARC reports to purge.', $tester->getDisplay());
    }

    private function createTeam(SubscriptionPlan $plan): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Purge Test',
            slug: 'purge-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan->value,
        );
        $team->popEvents();
        $this->em->persist($team);

        return $team;
    }

    private function createDomain(Team $team, string $name): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable('-1 day'),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        return $domain;
    }

    private function createReport(MonitoredDomain $domain, \DateTimeImmutable $processedAt): DmarcReport
    {
        $report = new DmarcReport(
            id: $this->identityProvider->nextIdentity(),
            monitoredDomain: $domain,
            reporterOrg: 'reporter.example.com',
            reporterEmail: 'reports@reporter.example.com',
            externalReportId: 'rep-'.bin2hex(random_bytes(6)),
            dateRangeBegin: $processedAt,
            dateRangeEnd: $processedAt,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'x',
            processedAt: $processedAt,
            sourceEnvelope: null,
        );
        $report->popEvents();
        $this->em->persist($report);

        return $report;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:dmarc:purge'));
    }
}
