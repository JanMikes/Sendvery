<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\ProcessDmarcReport;
use App\MessageHandler\ProcessDmarcReportHandler;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ProcessDmarcReportHandlerTest extends IntegrationTestCase
{
    public function testProcessesReportAndCreatesRecords(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team',
            slug: 'test-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $xml = file_get_contents(__DIR__.'/../../Fixtures/google-report.xml');
        assert(is_string($xml));

        $reportId = Uuid::uuid7();
        $handler = $this->getService(ProcessDmarcReportHandler::class);
        $handler(new ProcessDmarcReport(
            reportId: $reportId,
            domainId: $domainId,
            xmlContent: $xml,
        ));
        $em->flush();

        $report = $em->find(DmarcReport::class, $reportId);
        self::assertNotNull($report);
        self::assertSame('google.com', $report->reporterOrg);
        self::assertSame('17238456789012345678', $report->externalReportId);

        $records = $em->getRepository(DmarcRecord::class)->findBy(['dmarcReport' => $reportId->toString()]);
        self::assertCount(2, $records);
    }

    public function testSkipsDuplicateReport(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Dup Team',
            slug: 'dup-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'dup-example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $xml = file_get_contents(__DIR__.'/../../Fixtures/google-report.xml');
        assert(is_string($xml));

        $handler = $this->getService(ProcessDmarcReportHandler::class);

        // First import
        $handler(new ProcessDmarcReport(
            reportId: Uuid::uuid7(),
            domainId: $domainId,
            xmlContent: $xml,
        ));
        $em->flush();
        $em->clear();

        // Second import — should be skipped
        $secondReportId = Uuid::uuid7();
        $handler(new ProcessDmarcReport(
            reportId: $secondReportId,
            domainId: $domainId,
            xmlContent: $xml,
        ));
        $em->flush();

        $secondReport = $em->find(DmarcReport::class, $secondReportId);
        self::assertNull($secondReport);
    }

    public function testCompressesRawXml(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Compress Team',
            slug: 'compress-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'compress-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $xml = file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml');
        assert(is_string($xml));

        $reportId = Uuid::uuid7();
        $handler = $this->getService(ProcessDmarcReportHandler::class);
        $handler(new ProcessDmarcReport(
            reportId: $reportId,
            domainId: $domainId,
            xmlContent: $xml,
        ));
        $em->flush();

        $report = $em->find(DmarcReport::class, $reportId);
        self::assertNotNull($report);

        // Verify XML can be decompressed back
        $decompressed = gzuncompress(base64_decode($report->rawXml));
        self::assertSame($xml, $decompressed);
    }
}
