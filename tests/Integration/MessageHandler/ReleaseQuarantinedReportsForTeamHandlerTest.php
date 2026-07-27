<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Message\ReleaseQuarantinedReportsForTeam;
use App\MessageHandler\ReleaseQuarantinedReportsForTeamHandler;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Reports parked because a team ran out of monthly headroom must come back the
 * moment headroom does — otherwise `plan_overage` quarantine is a one-way trip
 * and the customer silently loses reports they paid to see.
 */
final class ReleaseQuarantinedReportsForTeamHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private ReleaseQuarantinedReportsForTeamHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(ReleaseQuarantinedReportsForTeamHandler::class);
    }

    #[Test]
    public function parkedReportsLandInTheDashboardOnceTheTeamHasHeadroomAgain(): void
    {
        $team = $this->createTeam(SubscriptionPlan::Free);
        $domain = $this->createVerifiedDomain($team, 'headroom-back.example');
        $parked = $this->park($domain->domain);
        $this->em->flush();
        $this->insertUsage($team->id, reportsParsed: 0);
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForTeam(teamId: $team->id));
        $this->em->flush();
        $this->em->clear();

        self::assertNull(
            $this->em->find(QuarantinedDmarcReport::class, $parked->id),
            'A released report no longer needs its holding row.',
        );
        self::assertNotNull(
            $this->em->getRepository(DmarcReport::class)->findOneBy(['monitoredDomain' => $domain->id->toString()]),
            'The report the cap withheld must show up as a normal report once the cap allows it.',
        );
    }

    #[Test]
    public function onlyAsManyReportsAsTheCapAllowsAreHandedBack(): void
    {
        // Free = 100 reports/month, 99 already parsed: exactly one may come back.
        $team = $this->createTeam(SubscriptionPlan::Free);
        $domain = $this->createVerifiedDomain($team, 'partial-release.example');
        $this->park($domain->domain);
        $this->park($domain->domain);
        $this->park($domain->domain);
        $this->em->flush();
        $this->insertUsage($team->id, reportsParsed: 99);
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForTeam(teamId: $team->id));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(
            1,
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
            'Releasing more than the remaining allowance would push the team back over its cap, and the ingestion gate would simply park them again.',
        );
        self::assertCount(
            2,
            $this->em->getRepository(QuarantinedDmarcReport::class)->findBy(['domainName' => $domain->domain]),
            'What does not fit stays parked and waits for the next period — it is never dropped.',
        );
    }

    #[Test]
    public function nothingIsHandedBackWhileTheTeamIsStillAtItsCap(): void
    {
        $team = $this->createTeam(SubscriptionPlan::Free);
        $domain = $this->createVerifiedDomain($team, 'still-full.example');
        $parked = $this->park($domain->domain);
        $this->em->flush();
        $this->insertUsage($team->id, reportsParsed: 100);
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForTeam(teamId: $team->id));
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull(
            $this->em->find(QuarantinedDmarcReport::class, $parked->id),
            'With zero headroom the report stays exactly where it is — held, not lost.',
        );
        self::assertSame(
            [],
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
        );
    }

    #[Test]
    public function aBiggerPlanUnlocksTheBacklogTheSmallerPlanWithheld(): void
    {
        // 100 reports parsed is the Free cap but only 10% of Personal's.
        $team = $this->createTeam(SubscriptionPlan::Personal);
        $domain = $this->createVerifiedDomain($team, 'upgraded.example');
        $this->park($domain->domain);
        $this->park($domain->domain);
        $this->em->flush();
        $this->insertUsage($team->id, reportsParsed: 100);
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForTeam(teamId: $team->id));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(
            2,
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
            'Unlocking the parked reports is what the customer bought when they upgraded.',
        );
    }

    #[Test]
    public function reportsForAnUnverifiedDomainAreNotForcedThroughByThisPath(): void
    {
        $team = $this->createTeam(SubscriptionPlan::Free);
        $domain = $this->createVerifiedDomain($team, 'unverified-again.example', verified: false);
        $parked = $this->park($domain->domain);
        $this->em->flush();
        $this->insertUsage($team->id, reportsParsed: 0);
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForTeam(teamId: $team->id));
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull(
            $this->em->find(QuarantinedDmarcReport::class, $parked->id),
            'Handing reports to a team that has not proven control of the domain is exactly what quarantine exists to prevent.',
        );
    }

    private function createTeam(SubscriptionPlan $plan): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Release For Team',
            slug: 'release-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan->value,
        );
        $team->popEvents();
        $this->em->persist($team);

        return $team;
    }

    private function createVerifiedDomain(Team $team, string $name, bool $verified = true): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $verified ? new \DateTimeImmutable('-1 day') : null,
        );
        $domain->popEvents();
        $this->em->persist($domain);

        return $domain;
    }

    private function park(string $domainName): QuarantinedDmarcReport
    {
        $identityProvider = $this->getService(IdentityProvider::class);

        $envelope = new ReceivedReportEmail(
            id: $identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<park-'.bin2hex(random_bytes(8)).'@test>',
            fromAddress: 'noreply-dmarc-support@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        $externalId = 'overage-'.bin2hex(random_bytes(8));
        $compressed = gzencode($this->reportXml($domainName, $externalId));
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: $identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: $externalId,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::PlanOverage,
            reportXmlGz: $compressed,
        );
        $this->em->persist($quarantine);

        return $quarantine;
    }

    private function reportXml(string $domainName, string $externalId): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feedback>
              <report_metadata>
                <org_name>google.com</org_name>
                <email>noreply-dmarc-support@google.com</email>
                <report_id>{$externalId}</report_id>
                <date_range><begin>1700000000</begin><end>1700086400</end></date_range>
              </report_metadata>
              <policy_published>
                <domain>{$domainName}</domain>
                <p>none</p>
              </policy_published>
              <record>
                <row>
                  <source_ip>1.2.3.4</source_ip>
                  <count>1</count>
                  <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
                </row>
                <identifiers><header_from>{$domainName}</header_from></identifiers>
                <auth_results><dkim><domain>{$domainName}</domain><result>pass</result></dkim></auth_results>
              </record>
            </feedback>
            XML;
    }

    private function insertUsage(UuidInterface $teamId, int $reportsParsed): void
    {
        $periodStart = $this->getService(ClockInterface::class)->now()
            ->modify('first day of this month')
            ->setTime(0, 0);

        $this->getService(Connection::class)->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :startsAt, :endsAt)',
            [
                'teamId' => $teamId->toString(),
                'count' => $reportsParsed,
                'startsAt' => $periodStart->format('Y-m-d H:i:s'),
                'endsAt' => $periodStart->modify('+1 month')->format('Y-m-d H:i:s'),
            ],
        );
    }
}
