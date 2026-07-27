<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Message\ReleaseQuarantinedReportsForDomain;
use App\MessageHandler\ReleaseQuarantinedReportsForDomainHandler;
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
 * Verifying a domain releases the reports that were waiting on it — but a
 * release is an ingestion like any other and increments the monthly counter,
 * so it has to obey the same cap. Otherwise verifying a domain is a way to walk
 * straight past the plan limit the inbox enforces.
 */
final class ReleaseQuarantinedReportsRespectsPlanCapTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private ReleaseQuarantinedReportsForDomainHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(ReleaseQuarantinedReportsForDomainHandler::class);
    }

    #[Test]
    public function verifyingADomainReleasesOnlyAsManyReportsAsTheCapAllows(): void
    {
        // Free = 100/month with 99 parsed: one of the three waiting reports fits.
        $domain = $this->setUpDomainWithWaitingReports('cap-release.example', waiting: 3, reportsParsed: 99);

        ($this->handler)(new ReleaseQuarantinedReportsForDomain(
            domainId: $domain->id,
            domainName: $domain->domain,
        ));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(
            1,
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
            'The cap applies whichever pipeline the report came through, releases included.',
        );
        self::assertCount(
            2,
            $this->em->getRepository(QuarantinedDmarcReport::class)->findBy(['domainName' => $domain->domain]),
            'What does not fit keeps waiting — a release that ignored the cap would be parked again immediately, deleting and re-creating the same rows on every trigger.',
        );
    }

    #[Test]
    public function reportsHeldBackByTheCapAreRelabelledSoNoRetentionPurgeCanDeleteThem(): void
    {
        $domain = $this->setUpDomainWithWaitingReports('cap-relabel.example', waiting: 2, reportsParsed: 100);

        ($this->handler)(new ReleaseQuarantinedReportsForDomain(
            domainId: $domain->id,
            domainName: $domain->domain,
        ));
        $this->em->flush();
        $this->em->clear();

        $stillWaiting = $this->em->getRepository(QuarantinedDmarcReport::class)
            ->findBy(['domainName' => $domain->domain]);

        self::assertCount(2, $stillWaiting);
        foreach ($stillWaiting as $row) {
            self::assertSame(
                QuarantineReason::PlanOverage,
                $row->reason,
                'The domain is verified now, so the only thing withholding this report is the plan cap — and a report withheld for a billing reason must never be deleted for a verification reason it no longer has.',
            );
        }
    }

    #[Test]
    public function everythingIsReleasedWhenTheTeamHasRoomForItAll(): void
    {
        $domain = $this->setUpDomainWithWaitingReports('cap-clear.example', waiting: 3, reportsParsed: 0);

        ($this->handler)(new ReleaseQuarantinedReportsForDomain(
            domainId: $domain->id,
            domainName: $domain->domain,
        ));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(
            3,
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
        );
        self::assertSame(
            [],
            $this->em->getRepository(QuarantinedDmarcReport::class)->findBy(['domainName' => $domain->domain]),
        );
    }

    private function setUpDomainWithWaitingReports(string $domainName, int $waiting, int $reportsParsed): MonitoredDomain
    {
        $identityProvider = $this->getService(IdentityProvider::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Cap Release',
            slug: 'cap-release-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: SubscriptionPlan::Free->value,
        );
        $team->popEvents();
        $this->em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $domainName,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable('-1 minute'),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        for ($i = 0; $i < $waiting; ++$i) {
            $envelope = new ReceivedReportEmail(
                id: $identityProvider->nextIdentity(),
                source: ReportSource::CentralInbox,
                messageId: '<wait-'.bin2hex(random_bytes(8)).'@test>',
                fromAddress: 'noreply-dmarc-support@google.com',
                subject: 'Report',
                receivedAt: new \DateTimeImmutable(),
                ingestedAt: new \DateTimeImmutable(),
                sizeBytes: 1,
                rawEml: 'x',
            );
            $this->em->persist($envelope);

            $externalId = 'waiting-'.bin2hex(random_bytes(8));
            $compressed = gzencode($this->reportXml($domainName, $externalId));
            assert(false !== $compressed);

            $this->em->persist(new QuarantinedDmarcReport(
                id: $identityProvider->nextIdentity(),
                receivedEmail: $envelope,
                domainName: $domainName,
                externalReportId: $externalId,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply-dmarc-support@google.com',
                dateRangeBegin: new \DateTimeImmutable('@1700000000'),
                dateRangeEnd: new \DateTimeImmutable('@1700086400'),
                quarantinedAt: new \DateTimeImmutable('-'.($i + 1).' hours'),
                expiresAt: new \DateTimeImmutable('+60 days'),
                reason: QuarantineReason::UnverifiedDomain,
                reportXmlGz: $compressed,
            ));
        }

        $this->em->flush();
        $this->insertUsage($team->id, $reportsParsed);
        $this->em->clear();

        return $domain;
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
