<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DmarcSummaryCommandTest extends IntegrationTestCase
{
    public function testShowsSummaryWithData(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Summary Test',
            slug: 'summary-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'summary-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-summary-1',
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
            dateRangeEnd: new \DateTimeImmutable(),
            policyDomain: 'summary-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $record = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'summary-test.com',
        );
        $em->persist($record);
        $em->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dmarc:summary');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Reports: 1', $display);
        self::assertStringContainsString('Total messages: 100', $display);
    }

    public function testShowsWarningWithNoData(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dmarc:summary');
        $tester = new CommandTester($command);

        $tester->execute(['--days' => '1', '--domain' => 'nonexistent-'.Uuid::uuid7()->toString().'.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No DMARC reports found', $tester->getDisplay());
    }
}
