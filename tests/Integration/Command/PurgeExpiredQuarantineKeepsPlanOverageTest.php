<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reports withheld because a team ran out of monthly plan headroom are the
 * customer's own data. A plan limit freezes data; it never destroys it
 * (`never-delete-user-data`), so no retention TTL may reach them.
 */
final class PurgeExpiredQuarantineKeepsPlanOverageTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
    }

    #[Test]
    public function aReportWithheldOnlyByThePlanCapIsNeverDeletedHoweverLongItWaits(): void
    {
        $overage = $this->makeQuarantine(
            QuarantineReason::PlanOverage,
            expiresAt: new \DateTimeImmutable('-2 years'),
        );
        $this->em->flush();

        $this->tester()->execute([]);
        $this->em->clear();

        self::assertNotNull(
            $this->em->find(QuarantinedDmarcReport::class, $overage->id),
            'A paying customer\'s report, parked only because their plan cap was full, must still exist long after any TTL — the cap withholds data, it does not delete it.',
        );
    }

    #[Test]
    public function reportsForDomainsNobodyEverProvedOwnershipOfStillExpire(): void
    {
        $unknown = $this->makeQuarantine(
            QuarantineReason::UnknownDomain,
            expiresAt: new \DateTimeImmutable('-1 day'),
        );
        $unverified = $this->makeQuarantine(
            QuarantineReason::UnverifiedDomain,
            expiresAt: new \DateTimeImmutable('-1 day'),
        );
        $this->em->flush();

        $this->tester()->execute([]);
        $this->em->clear();

        self::assertNull(
            $this->em->find(QuarantinedDmarcReport::class, $unknown->id),
            'Mail for a domain no team ever claimed has no owner to hand it to, so the retention TTL must still clear it.',
        );
        self::assertNull(
            $this->em->find(QuarantinedDmarcReport::class, $unverified->id),
            'A domain that was never verified inside the retention window still expires.',
        );
    }

    #[Test]
    public function anExpiredPlanOverageReportIsNotEvenLoadedByThePurgeQuery(): void
    {
        $this->makeQuarantine(
            QuarantineReason::PlanOverage,
            expiresAt: new \DateTimeImmutable('-2 years'),
        );
        $this->em->flush();

        $expired = $this->getService(QuarantinedDmarcReportRepository::class)
            ->findExpired(new \DateTimeImmutable());

        self::assertSame(
            [],
            $expired,
            'Plan-overage rows are excluded in SQL: they are never deleted, so re-reading them (compressed report blob included) on every nightly run would be pure waste that grows forever.',
        );
    }

    #[Test]
    public function theTtlPurgeStillReportsHavingNothingToDoWhenOnlyProtectedRowsAreExpired(): void
    {
        $this->makeQuarantine(
            QuarantineReason::PlanOverage,
            expiresAt: new \DateTimeImmutable('-2 years'),
        );
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No expired quarantined reports.', $tester->getDisplay());
    }

    private function makeQuarantine(QuarantineReason $reason, \DateTimeImmutable $expiresAt): QuarantinedDmarcReport
    {
        $identityProvider = $this->getService(IdentityProvider::class);

        $envelope = new ReceivedReportEmail(
            id: $identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<keep-'.bin2hex(random_bytes(8)).'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable('-2 years'),
            ingestedAt: new \DateTimeImmutable('-2 years'),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $row = new QuarantinedDmarcReport(
            id: $identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: 'purge-protection.example',
            externalReportId: 'ext-'.bin2hex(random_bytes(4)),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('-2 years'),
            dateRangeEnd: new \DateTimeImmutable('-2 years +1 day'),
            quarantinedAt: new \DateTimeImmutable('-2 years'),
            expiresAt: $expiresAt,
            reason: $reason,
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
