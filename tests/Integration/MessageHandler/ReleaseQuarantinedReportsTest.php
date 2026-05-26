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
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ReleaseQuarantinedReportsTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private IdentityProvider $identityProvider;
    private ReleaseQuarantinedReportsForDomainHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
        $this->handler = $this->getService(ReleaseQuarantinedReportsForDomainHandler::class);
    }

    public function testReleasesQuarantinedReportsAndRemovesRow(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team, 'release-test.com');
        $envelope = $this->createEnvelope();
        $quarantined = $this->createQuarantine($envelope, 'release-test.com');
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForDomain(
            domainId: $domain->id,
            domainName: 'release-test.com',
        ));
        $this->em->flush();

        $this->em->clear();

        // Quarantined row is gone after release.
        $stillThere = $this->em->find(QuarantinedDmarcReport::class, $quarantined->id);
        self::assertNull($stillThere);

        // Report landed in the dashboard for the verified team.
        $report = $this->em->getRepository(DmarcReport::class)
            ->findOneBy(['monitoredDomain' => $domain->id->toString()]);
        self::assertNotNull($report);
    }

    public function testNoopWhenNoQuarantineMatches(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team, 'noop-domain.com');
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForDomain(
            domainId: $domain->id,
            domainName: 'noop-domain.com',
        ));
        $this->em->flush();

        $this->em->clear();
        $reports = $this->em->getRepository(DmarcReport::class)
            ->findBy(['monitoredDomain' => $domain->id->toString()]);
        self::assertSame([], $reports);
    }

    public function testReleaseIsCaseInsensitive(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team, 'mixed-case-domain.com');
        $envelope = $this->createEnvelope();
        $this->createQuarantine($envelope, 'mixed-case-domain.com');
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ReleaseQuarantinedReportsForDomain(
            domainId: $domain->id,
            domainName: 'MIXED-CASE-DOMAIN.COM',
        ));
        $this->em->flush();

        $this->em->clear();
        $reports = $this->em->getRepository(DmarcReport::class)
            ->findBy(['monitoredDomain' => $domain->id->toString()]);
        self::assertCount(1, $reports);
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Release Test',
            slug: 'release-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
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
        $this->em->persist($domain);

        return $domain;
    }

    private function createEnvelope(): ReceivedReportEmail
    {
        $envelope = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<envelope-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        return $envelope;
    }

    private function createQuarantine(ReceivedReportEmail $envelope, string $domainName): QuarantinedDmarcReport
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feedback>
  <report_metadata>
    <org_name>google.com</org_name>
    <email>noreply-dmarc-support@google.com</email>
    <report_id>quarantine-release-{$envelope->id->toString()}</report_id>
    <date_range><begin>1700000000</begin><end>1700086400</end></date_range>
  </report_metadata>
  <policy_published>
    <domain>$domainName</domain>
    <p>none</p>
  </policy_published>
  <record>
    <row>
      <source_ip>1.2.3.4</source_ip>
      <count>1</count>
      <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
    </row>
    <identifiers><header_from>$domainName</header_from></identifiers>
    <auth_results><dkim><domain>$domainName</domain><result>pass</result></dkim></auth_results>
  </record>
</feedback>
XML;

        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: $this->identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'quarantine-release-'.$envelope->id->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::UnverifiedDomain,
            reportXmlGz: $compressed,
        );
        $this->em->persist($quarantine);

        return $quarantine;
    }
}
