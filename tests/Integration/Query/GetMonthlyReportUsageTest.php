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
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class GetMonthlyReportUsageTest extends IntegrationTestCase
{
    public function testReturnsNullWhenNoTeamUsageRow(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $team = $this->createTeam('no-usage');

        $result = $query->forTeam($team->id->toString());

        self::assertNull($result);
    }

    public function testReturnsCurrentCountAndPeriodEndsAt(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $team = $this->createTeam('with-usage');
        // The period must be LIVE. A finished period deliberately reads as zero
        // usage — see GetMonthlyReportUsageStalePeriodTest — so pinning this
        // assertion to fixed past dates would test the stale path by accident.
        [$periodStart, $periodEnd] = $this->currentPeriod();
        $this->insertTeamUsage($team->id, 250, $periodStart, $periodEnd);

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(250, $result->currentCount);
        self::assertSame($periodEnd, $result->periodEndsAt->format('Y-m-d H:i:s'));
    }

    public function testReturnsZeroOverageWhenNoneExist(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $team = $this->createTeam('no-overage');
        [$periodStart, $periodEnd] = $this->currentPeriod();
        $this->insertTeamUsage($team->id, 100, $periodStart, $periodEnd);

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(0, $result->planOverageQuarantineCount);
    }

    public function testCountsPlanOverageReports(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam('with-overage');
        $domain = $this->createDomain($team, 'overage-test.example');
        [$periodStart, $periodEnd] = $this->currentPeriod();
        $this->insertTeamUsage($team->id, 100, $periodStart, $periodEnd);

        $this->createQuarantine($domain->domain, QuarantineReason::PlanOverage);
        $this->createQuarantine($domain->domain, QuarantineReason::PlanOverage);
        $em->flush();

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(2, $result->planOverageQuarantineCount);
    }

    public function testExcludesOtherTeamsQuarantine(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $em = $this->getService(EntityManagerInterface::class);

        $teamA = $this->createTeam('team-a');
        $teamB = $this->createTeam('team-b');
        $this->createDomain($teamA, 'team-a.example');
        $domainB = $this->createDomain($teamB, 'team-b.example');

        $this->insertTeamUsage($teamA->id, 100, '2026-05-01 00:00:00', '2026-06-01 00:00:00');

        // Quarantine belongs to team B's domain — must not count for team A.
        $this->createQuarantine($domainB->domain, QuarantineReason::PlanOverage);
        $em->flush();

        $result = $query->forTeam($teamA->id->toString());

        self::assertNotNull($result);
        self::assertSame(0, $result->planOverageQuarantineCount);
    }

    public function testExcludesNonOverageQuarantineReasons(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam('non-overage');
        $domain = $this->createDomain($team, 'non-overage.example');
        [$periodStart, $periodEnd] = $this->currentPeriod();
        $this->insertTeamUsage($team->id, 100, $periodStart, $periodEnd);

        $this->createQuarantine($domain->domain, QuarantineReason::UnknownDomain);
        $this->createQuarantine($domain->domain, QuarantineReason::UnverifiedDomain);
        $em->flush();

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(0, $result->planOverageQuarantineCount);
    }

    public function testCountsMultiplePlanOverageForSameDomain(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam('multi-overage');
        $domain = $this->createDomain($team, 'multi.example');
        [$periodStart, $periodEnd] = $this->currentPeriod();
        $this->insertTeamUsage($team->id, 100, $periodStart, $periodEnd);

        for ($i = 0; $i < 5; ++$i) {
            $this->createQuarantine($domain->domain, QuarantineReason::PlanOverage);
        }
        $em->flush();

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(5, $result->planOverageQuarantineCount);
    }

    public function testPeriodEndsAtHydratesCorrectly(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $team = $this->createTeam('hydrate-period');
        [$periodStart, $periodEnd] = $this->currentPeriod();
        $this->insertTeamUsage($team->id, 0, $periodStart, $periodEnd);

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertInstanceOf(\DateTimeImmutable::class, $result->periodEndsAt);
        self::assertSame(substr($periodEnd, 0, 10), $result->periodEndsAt->format('Y-m-d'));
    }

    /**
     * The live monthly window, matching PlanEnforcement::ensureCurrentPeriod().
     *
     * @return array{0: string, 1: string}
     */
    private function currentPeriod(): array
    {
        $start = $this->getService(ClockInterface::class)->now()
            ->modify('first day of this month')
            ->setTime(0, 0);

        return [$start->format('Y-m-d H:i:s'), $start->modify('+1 month')->format('Y-m-d H:i:s')];
    }

    private function createTeam(string $prefix): Team
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = new Team(
            id: Uuid::uuid7(),
            name: $prefix.' team',
            slug: $prefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function createDomain(Team $team, string $domainName): MonitoredDomain
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $domainName,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    private function insertTeamUsage(UuidInterface $teamId, int $count, string $startsAt, string $endsAt): void
    {
        $connection = $this->getService(Connection::class);
        $connection->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :startsAt, :endsAt)',
            [
                'teamId' => $teamId->toString(),
                'count' => $count,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ],
        );
    }

    private function createQuarantine(string $domainName, QuarantineReason $reason): QuarantinedDmarcReport
    {
        $em = $this->getService(EntityManagerInterface::class);

        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<envelope-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            // "Now": the overage count is scoped to the live usage period, and
            // an hour before midnight on the 1st would be the previous one.
            quarantinedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: $reason,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);

        return $quarantine;
    }
}
