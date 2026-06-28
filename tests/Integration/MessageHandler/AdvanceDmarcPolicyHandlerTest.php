<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\AdvanceDmarcPolicy;
use App\MessageHandler\AdvanceDmarcPolicyHandler;
use App\Repository\MonitoredDomainRepository;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class AdvanceDmarcPolicyHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function refusesToAdvanceWhenNotReady(): void
    {
        // No reports → thin data → not eligible.
        $domainId = $this->managedDomain('thin.example', readyData: false);

        $this->handle(new AdvanceDmarcPolicy($domainId, $this->teamIdFor($domainId), null));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcPolicy::None, $domain->managedPolicyP, 'Must not tighten when readiness is not met.');
    }

    #[Test]
    public function advancesToTheRecommendedTierWhenReady(): void
    {
        $domainId = $this->managedDomain('ready.example', readyData: true);

        $this->handle(new AdvanceDmarcPolicy($domainId, $this->teamIdFor($domainId), null));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP, 'A ready none-stage domain advances to quarantine.');
        self::assertSame(AutoRampStage::Quarantine, $domain->autoRampStage);
    }

    #[Test]
    public function rejectsAForgedRequestForAnotherTeamsDomain(): void
    {
        $domainId = $this->managedDomain('acme.example', readyData: false);

        $this->expectException(\RuntimeException::class);
        $this->handle(new AdvanceDmarcPolicy($domainId, Uuid::uuid7()->toString(), null));
    }

    #[Test]
    public function hardRefusesAFreePlanTeam(): void
    {
        $domainId = $this->managedDomain('acme.example', readyData: true, plan: 'free');

        $this->expectException(\App\Exceptions\ManagedDmarcNotAvailable::class);
        $this->handle(new AdvanceDmarcPolicy($domainId, $this->teamIdFor($domainId), null));
    }

    private function handle(AdvanceDmarcPolicy $message): void
    {
        $this->getService(AdvanceDmarcPolicyHandler::class)($message);
        $this->getService(EntityManagerInterface::class)->flush();
    }

    private function managedDomain(string $domain, bool $readyData, string $plan = 'pro'): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Advance',
            slug: 'advance-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan,
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: $domain,
            createdAt: $now->modify('-60 days'),
            firstReportAt: $now->modify('-40 days'),
        );
        $entity->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $entity->managedPolicyP = DmarcPolicy::None;
        $entity->autoRampStage = AutoRampStage::Monitoring;
        $entity->managedDmarcEnabledAt = $now->modify('-40 days');
        $entity->cnameVerifiedAt = $now->modify('-39 days');
        $entity->lastPolicyChangeAt = $now->modify('-40 days');
        $entity->popEvents();
        $em->persist($entity);

        if ($readyData) {
            // 3 reports, 2 distinct passing source IPs, 100% aligned, no authorized failures.
            for ($i = 0; $i < 3; ++$i) {
                $report = new DmarcReport(
                    id: Uuid::uuid7(),
                    monitoredDomain: $entity,
                    reporterOrg: 'google.com',
                    reporterEmail: 'noreply@google.com',
                    externalReportId: 'ext-'.Uuid::uuid7()->toString(),
                    dateRangeBegin: $now->modify('-3 days'),
                    dateRangeEnd: $now->modify('-2 days'),
                    policyDomain: $domain,
                    policyAdkim: DmarcAlignment::Relaxed,
                    policyAspf: DmarcAlignment::Relaxed,
                    policyP: DmarcPolicy::None,
                    policySp: null,
                    policyPct: 100,
                    rawXml: '<feedback></feedback>',
                    processedAt: $now,
                );
                $em->persist($report);
                $em->persist(new DmarcRecord(id: Uuid::uuid7(), dmarcReport: $report, sourceIp: '1.1.1.1', count: 50, disposition: Disposition::None, dkimResult: AuthResult::Pass, spfResult: AuthResult::Pass, headerFrom: $domain));
                $em->persist(new DmarcRecord(id: Uuid::uuid7(), dmarcReport: $report, sourceIp: '2.2.2.2', count: 50, disposition: Disposition::None, dkimResult: AuthResult::Pass, spfResult: AuthResult::Pass, headerFrom: $domain));
            }
        }

        $em->flush();

        return $domainId;
    }

    private function teamIdFor(UuidInterface $domainId): string
    {
        return $this->getService(MonitoredDomainRepository::class)->get($domainId)->team->id->toString();
    }
}
