<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Message\ReleaseQuarantinedReportsForTeam;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A monthly cap returns capacity every period, so the reports it withheld must
 * come back every period too. An upgrade cannot be the only way out — a team
 * that never upgrades would keep its own reports parked forever.
 */
final class ResetMonthlyUsageCountersReleasesParkedReportsTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
    }

    #[Test]
    public function rollingTheMonthlyCounterAsksEachAffectedTeamForItsParkedReports(): void
    {
        $team = $this->createTeamWithParkedReport('rollover-release.example');

        $this->tester()->execute([]);

        $releases = array_values(array_filter(
            array_map(
                static fn ($envelope): object => $envelope->getMessage(),
                $this->asyncTransport()->getSent(),
            ),
            static fn (object $message): bool => $message instanceof ReleaseQuarantinedReportsForTeam,
        ));

        self::assertCount(
            1,
            $releases,
            'The period roll is where monthly capacity actually returns, so it must be a release trigger.',
        );
        self::assertTrue($team->id->equals($releases[0]->teamId));
    }

    #[Test]
    public function teamsWithNothingParkedAreNotAskedToReleaseAnything(): void
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Nothing Parked',
            slug: 'nothing-parked-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $this->em->persist($team);
        $this->em->flush();

        $tester = $this->tester();
        $tester->execute([]);

        $releases = array_filter(
            $this->asyncTransport()->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof ReleaseQuarantinedReportsForTeam,
        );

        self::assertSame([], $releases);
        self::assertStringNotContainsString('Queued plan-overage report release', $tester->getDisplay());
    }

    #[Test]
    public function theRunSaysHowManyTeamsWereAskedToRelease(): void
    {
        $this->createTeamWithParkedReport('rollover-output.example');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Queued plan-overage report release for 1 team(s).', $tester->getDisplay());
    }

    private function createTeamWithParkedReport(string $domainName): Team
    {
        $identityProvider = $this->getService(IdentityProvider::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Rollover Release',
            slug: 'rollover-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $this->em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $domainName,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable('-1 day'),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        $envelope = new ReceivedReportEmail(
            id: $identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<rollover-'.bin2hex(random_bytes(8)).'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $this->em->persist(new QuarantinedDmarcReport(
            id: $identityProvider->nextIdentity(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.bin2hex(random_bytes(4)),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::PlanOverage,
            reportXmlGz: $compressed,
        ));

        $this->em->flush();

        return $team;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:usage:reset'));
    }
}
