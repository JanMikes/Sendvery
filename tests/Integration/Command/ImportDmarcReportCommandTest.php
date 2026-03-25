<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportDmarcReportCommandTest extends IntegrationTestCase
{
    public function testImportsXmlFile(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Import Test',
            slug: 'import-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'import-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dmarc:import');
        $tester = new CommandTester($command);

        $tester->execute([
            'file' => __DIR__.'/../../Fixtures/google-report.xml',
            '--domain-id' => $domainId->toString(),
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Report #1 imported', $tester->getDisplay());

        $reports = $em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domainId->toString()]);
        self::assertCount(1, $reports);
    }

    public function testImportsGzFile(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'GZ Import',
            slug: 'gz-import-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'gz-import.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dmarc:import');
        $tester = new CommandTester($command);

        $tester->execute([
            'file' => __DIR__.'/../../Fixtures/google-report.xml.gz',
            '--domain-id' => $domainId->toString(),
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Report #1 imported', $tester->getDisplay());
    }

    public function testFailsOnMissingFile(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dmarc:import');
        $tester = new CommandTester($command);

        $tester->execute([
            'file' => '/nonexistent/file.xml',
            '--domain-id' => Uuid::uuid7()->toString(),
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('File not found', $tester->getDisplay());
    }

    public function testFailsWithoutDomainId(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dmarc:import');
        $tester = new CommandTester($command);

        $tester->execute([
            'file' => __DIR__.'/../../Fixtures/google-report.xml',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--domain-id option is required', $tester->getDisplay());
    }
}
