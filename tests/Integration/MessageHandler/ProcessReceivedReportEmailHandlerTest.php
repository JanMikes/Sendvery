<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Message\ProcessReceivedReportEmail;
use App\MessageHandler\ProcessReceivedReportEmailHandler;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ProcessReceivedReportEmailHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private ProcessReceivedReportEmailHandler $handler;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(ProcessReceivedReportEmailHandler::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testRoutesReportToVerifiedTeam(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team, 'example.com', verified: true);
        $envelope = $this->persistEnvelope($this->buildEmlWithReport('example.com', '<routed-1@google.com>'));
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ProcessReceivedReportEmail(envelopeId: $envelope->id));

        $this->em->clear();
        $reloaded = $this->em->find(ReceivedReportEmail::class, $envelope->id);
        self::assertNotNull($reloaded);
        self::assertSame(EnvelopeProcessingStatus::Parsed, $reloaded->processingStatus);

        $report = $this->em->getRepository(DmarcReport::class)
            ->findOneBy(['monitoredDomain' => $domain->id->toString()]);
        self::assertNotNull($report);
        self::assertSame($domain->id->toString(), $report->monitoredDomain->id->toString());
    }

    public function testQuarantinesReportForUnverifiedDomain(): void
    {
        $team = $this->createTeam();
        $this->createDomain($team, 'pending.com', verified: false);
        $envelope = $this->persistEnvelope($this->buildEmlWithReport('pending.com', '<quar-1@google.com>'));
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ProcessReceivedReportEmail(envelopeId: $envelope->id));

        $this->em->clear();
        $reloaded = $this->em->find(ReceivedReportEmail::class, $envelope->id);
        self::assertNotNull($reloaded);
        self::assertSame(EnvelopeProcessingStatus::Quarantined, $reloaded->processingStatus);

        $quarantine = $this->em->getRepository(QuarantinedDmarcReport::class)
            ->findOneBy(['domainName' => 'pending.com']);
        self::assertNotNull($quarantine);
        self::assertSame(QuarantineReason::UnverifiedDomain, $quarantine->reason);
    }

    public function testQuarantinesReportForUnknownDomain(): void
    {
        $envelope = $this->persistEnvelope($this->buildEmlWithReport('mystery.com', '<quar-2@google.com>'));
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ProcessReceivedReportEmail(envelopeId: $envelope->id));

        $this->em->clear();
        $quarantine = $this->em->getRepository(QuarantinedDmarcReport::class)
            ->findOneBy(['domainName' => 'mystery.com']);
        self::assertNotNull($quarantine);
        self::assertSame(QuarantineReason::UnknownDomain, $quarantine->reason);
    }

    public function testIgnoresEnvelopeWithNoAttachments(): void
    {
        $envelope = $this->persistEnvelope($this->buildEmlBodyOnly());
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ProcessReceivedReportEmail(envelopeId: $envelope->id));

        $this->em->clear();
        $reloaded = $this->em->find(ReceivedReportEmail::class, $envelope->id);
        self::assertNotNull($reloaded);
        self::assertSame(EnvelopeProcessingStatus::Ignored, $reloaded->processingStatus);
        self::assertNotNull($reloaded->processingError);
    }

    public function testIncrementsAttemptsOnEachInvocation(): void
    {
        $envelope = $this->persistEnvelope($this->buildEmlBodyOnly());
        $this->em->flush();
        $this->em->clear();

        ($this->handler)(new ProcessReceivedReportEmail(envelopeId: $envelope->id));
        $this->em->clear();
        ($this->handler)(new ProcessReceivedReportEmail(envelopeId: $envelope->id));

        $this->em->clear();
        $reloaded = $this->em->find(ReceivedReportEmail::class, $envelope->id);
        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->attempts);
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Routing Test',
            slug: 'routing-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        return $team;
    }

    private function createDomain(Team $team, string $name, bool $verified): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $verified ? new \DateTimeImmutable('-1 day') : null,
        );
        $this->em->persist($domain);

        return $domain;
    }

    private function persistEnvelope(string $rawEml): ReceivedReportEmail
    {
        $envelope = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<envelope-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply-dmarc-support@google.com',
            subject: 'Report Domain',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: strlen($rawEml),
            rawEml: $rawEml,
        );
        $this->em->persist($envelope);

        return $envelope;
    }

    private function buildEmlWithReport(string $policyDomain, string $messageId): string
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feedback>
  <report_metadata>
    <org_name>google.com</org_name>
    <email>noreply-dmarc-support@google.com</email>
    <report_id>routed-1</report_id>
    <date_range><begin>1700000000</begin><end>1700086400</end></date_range>
  </report_metadata>
  <policy_published>
    <domain>$policyDomain</domain>
    <p>none</p>
  </policy_published>
  <record>
    <row>
      <source_ip>1.2.3.4</source_ip>
      <count>1</count>
      <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
    </row>
    <identifiers><header_from>$policyDomain</header_from></identifiers>
    <auth_results><dkim><domain>$policyDomain</domain><result>pass</result></dkim></auth_results>
  </record>
</feedback>
XML;

        // Update report_id per messageId for unique uniqueness across tests.
        $xml = str_replace('<report_id>routed-1</report_id>', '<report_id>'.trim($messageId, '<>').'</report_id>', $xml);

        $base64Xml = chunk_split(base64_encode($xml), 76, "\r\n");
        $boundary = 'b-'.bin2hex(random_bytes(8));

        return implode("\r\n", [
            'From: noreply-dmarc-support@google.com',
            'To: reports@sendvery.test',
            "Subject: Report Domain: $policyDomain",
            "Message-ID: $messageId",
            'Date: Fri, 22 May 2026 08:00:00 +0000',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
            '',
            "--$boundary",
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Aggregate report attached.',
            '',
            "--$boundary",
            'Content-Type: application/xml; name="report.xml"',
            'Content-Disposition: attachment; filename="report.xml"',
            'Content-Transfer-Encoding: base64',
            '',
            $base64Xml,
            "--$boundary--",
            '',
        ]);
    }

    private function buildEmlBodyOnly(): string
    {
        return implode("\r\n", [
            'From: spammer@example.com',
            'To: reports@sendvery.test',
            'Subject: I am not a DMARC report',
            'Message-ID: <body-'.bin2hex(random_bytes(8)).'@example.com>',
            'Date: Fri, 22 May 2026 08:00:00 +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Just some plain text, no attachments.',
            '',
        ]);
    }
}
