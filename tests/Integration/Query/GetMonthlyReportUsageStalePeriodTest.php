<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\Team;
use App\Query\GetMonthlyReportUsage;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * `team_usage` holds one mutable row per team, rolled forward by the
 * `0 0 * * *` `sendvery:usage:reset` cron and lazily by PlanEnforcement. When
 * neither has run, the stored counter belongs to a month that has already
 * finished — and the billing page was reading it as the current month's usage.
 *
 * A team whose usage had genuinely reset to zero was shown last month's figure
 * in red at 94% of its cap, alongside "Resets <a date in the past>", plus an
 * unearned "Reports this month" upsell card on `/app`. Reads now apply the same
 * period rule the enforcement path applies, so what a customer is shown and what
 * they are actually charged against can never disagree.
 */
final class GetMonthlyReportUsageStalePeriodTest extends IntegrationTestCase
{
    #[Test]
    public function usageFromAFinishedPeriodReadsAsZeroForTheCurrentMonth(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $team = $this->createTeam('stale-period');
        $now = $this->getService(ClockInterface::class)->now();
        $finished = $now->modify('first day of this month')->setTime(0, 0);

        $this->insertTeamUsage(
            $team->id->toString(),
            940,
            $finished->modify('-1 month')->format('Y-m-d H:i:s'),
            $finished->format('Y-m-d H:i:s'),
        );

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(
            0,
            $result->currentCount,
            'The stored counter belongs to a month that has ended, so the current month has zero usage — not 940.',
        );
        self::assertGreaterThan(
            $now,
            $result->periodEndsAt,
            'The reset date shown to the customer must be in the future; a past date reads as a broken billing page.',
        );
    }

    #[Test]
    public function usageInsideTheCurrentPeriodIsReportedAsStored(): void
    {
        $query = $this->getService(GetMonthlyReportUsage::class);
        $team = $this->createTeam('current-period');
        $now = $this->getService(ClockInterface::class)->now();
        $periodStart = $now->modify('first day of this month')->setTime(0, 0);
        $periodEnd = $periodStart->modify('+1 month');

        $this->insertTeamUsage(
            $team->id->toString(),
            250,
            $periodStart->format('Y-m-d H:i:s'),
            $periodEnd->format('Y-m-d H:i:s'),
        );

        $result = $query->forTeam($team->id->toString());

        self::assertNotNull($result);
        self::assertSame(250, $result->currentCount, 'A live period must report its real counter untouched.');
        self::assertSame($periodEnd->format('Y-m-d H:i:s'), $result->periodEndsAt->format('Y-m-d H:i:s'));
    }

    private function createTeam(string $slugPrefix): Team
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Usage '.$slugPrefix,
            slug: $slugPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function insertTeamUsage(string $teamId, int $count, string $periodStart, string $periodEnd): void
    {
        $this->getService(Connection::class)->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :start, :end)',
            [
                'teamId' => $teamId,
                'count' => $count,
                'start' => $periodStart,
                'end' => $periodEnd,
            ],
        );
    }
}
