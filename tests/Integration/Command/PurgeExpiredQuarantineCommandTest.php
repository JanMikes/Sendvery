<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeExpiredQuarantineCommandTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testDeletesExpiredAndKeepsFresh(): void
    {
        $expired = $this->makeQuarantine(expiresAt: new \DateTimeImmutable('-1 day'));
        $fresh = $this->makeQuarantine(expiresAt: new \DateTimeImmutable('+10 days'));
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $this->em->clear();

        self::assertNull($this->em->find(QuarantinedDmarcReport::class, $expired->id));
        self::assertNotNull($this->em->find(QuarantinedDmarcReport::class, $fresh->id));
    }

    public function testIsANoopWhenNothingExpired(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No expired quarantined reports.', $tester->getDisplay());
    }

    private function makeQuarantine(\DateTimeImmutable $expiresAt): QuarantinedDmarcReport
    {
        $envelope = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<q-'.bin2hex(random_bytes(8)).'@x>',
            fromAddress: 'a@example.com',
            subject: 'x',
            receivedAt: new \DateTimeImmutable('-30 days'),
            ingestedAt: new \DateTimeImmutable('-30 days'),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        $xml = '<feedback/>';
        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $row = new QuarantinedDmarcReport(
            id: $this->identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: 'expired-test.com',
            externalReportId: 'r-'.bin2hex(random_bytes(4)),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('-30 days'),
            dateRangeEnd: new \DateTimeImmutable('-29 days'),
            quarantinedAt: new \DateTimeImmutable('-30 days'),
            expiresAt: $expiresAt,
            reason: QuarantineReason::UnknownDomain,
            reportXmlGz: $compressed,
        );
        $this->em->persist($row);

        return $row;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:reports:quarantine:purge'));
    }
}
