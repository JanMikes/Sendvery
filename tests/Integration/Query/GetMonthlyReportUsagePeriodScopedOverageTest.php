<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Query\GetMonthlyReportUsage;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * The billing page says "N reports waiting — received after you hit this
 * month's cap". The count has to mean the same period as the usage figure next
 * to it, or the sentence is claiming a period the number doesn't have and the
 * same "12 reports waiting" follows the user around every month forever.
 *
 * `/app/quarantine` stays the complete, unbounded view of everything parked.
 */
final class GetMonthlyReportUsagePeriodScopedOverageTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
    }

    #[Test]
    public function reportsParkedThisPeriodAreCounted(): void
    {
        $team = $this->createTeam('overage-current');
        $domain = $this->createDomain($team, 'overage-current.example');
        $this->parkAt($domain->domain, $this->clock()->now()->modify('-1 hour'));
        $this->insertUsageForCurrentPeriod($team->id);

        $result = $this->getService(GetMonthlyReportUsage::class)->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(1, $result->planOverageQuarantineCount);
    }

    #[Test]
    public function reportsParkedInAnEarlierPeriodDoNotKeepWarningAboutThisMonthsCap(): void
    {
        $team = $this->createTeam('overage-stale');
        $domain = $this->createDomain($team, 'overage-stale.example');
        // Last day of the previous month — always outside the live window.
        $this->parkAt($domain->domain, $this->clock()->now()->modify('first day of this month')->setTime(0, 0)->modify('-1 day'));
        $this->insertUsageForCurrentPeriod($team->id);

        $result = $this->getService(GetMonthlyReportUsage::class)->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(
            0,
            $result->planOverageQuarantineCount,
            'A report parked under last month\'s cap is not what this month\'s cap cost the user; the quarantine page is where the full backlog lives.',
        );
    }

    #[Test]
    public function aTeamWhoseStoredPeriodHasAlreadyFinishedIsScoredAgainstTheLiveMonth(): void
    {
        // The stored row is stale (nothing has rolled it yet), so the query
        // applies the live month read-only — exactly as it does for the usage
        // figure beside it, so the two can never disagree.
        $team = $this->createTeam('overage-stale-row');
        $domain = $this->createDomain($team, 'overage-stale-row.example');
        $this->parkAt($domain->domain, $this->clock()->now()->modify('-1 hour'));

        $finished = $this->clock()->now()->modify('-2 months')->modify('first day of this month')->setTime(0, 0);
        $this->insertUsage($team->id, 100, $finished, $finished->modify('+1 month'));

        $result = $this->getService(GetMonthlyReportUsage::class)->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(0, $result->currentCount, 'A finished period reads as zero usage.');
        self::assertSame(
            1,
            $result->planOverageQuarantineCount,
            'A report parked an hour ago belongs to the live month whatever the stale row says.',
        );
    }

    private function clock(): ClockInterface
    {
        return $this->getService(ClockInterface::class);
    }

    private function createTeam(string $prefix): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: $prefix.' team',
            slug: $prefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createDomain(Team $team, string $domainName): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $domainName,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable('-1 day'),
        );
        $domain->popEvents();
        $this->em->persist($domain);
        $this->em->flush();

        return $domain;
    }

    private function parkAt(string $domainName, \DateTimeImmutable $quarantinedAt): void
    {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<scope-'.bin2hex(random_bytes(8)).'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: $quarantinedAt,
            ingestedAt: $quarantinedAt,
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $this->em->persist(new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.bin2hex(random_bytes(4)),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: $quarantinedAt->modify('-1 day'),
            dateRangeEnd: $quarantinedAt,
            quarantinedAt: $quarantinedAt,
            expiresAt: $quarantinedAt->modify('+60 days'),
            reason: QuarantineReason::PlanOverage,
            reportXmlGz: $compressed,
        ));
        $this->em->flush();
    }

    private function insertUsageForCurrentPeriod(UuidInterface $teamId): void
    {
        $periodStart = $this->clock()->now()->modify('first day of this month')->setTime(0, 0);
        $this->insertUsage($teamId, 100, $periodStart, $periodStart->modify('+1 month'));
    }

    private function insertUsage(UuidInterface $teamId, int $count, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        $this->getService(Connection::class)->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :startsAt, :endsAt)',
            [
                'teamId' => $teamId->toString(),
                'count' => $count,
                'startsAt' => $startsAt->format('Y-m-d H:i:s'),
                'endsAt' => $endsAt->format('Y-m-d H:i:s'),
            ],
        );
    }
}
