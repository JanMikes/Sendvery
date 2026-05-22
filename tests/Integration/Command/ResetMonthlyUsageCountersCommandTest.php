<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Team;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetMonthlyUsageCountersCommandTest extends IntegrationTestCase
{
    public function testReportsZeroWhenNoCountersExist(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No usage counters to reset.', $tester->getDisplay());
    }

    public function testResetsExpiredReportAndAiCounters(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $connection = $this->getService(Connection::class);

        $team = $this->createTeam($em);
        $teamId = $team->id->toString();

        // Bootstrap counters via PlanEnforcement (creates rows for current period).
        $enforcement->incrementMonthlyReportCount($teamId);
        $enforcement->incrementOnDemandAiUsage($teamId);

        // Manually backdate both rows to make them appear expired.
        $stale = (new \DateTimeImmutable('-2 months'))->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'UPDATE team_usage SET period_ends_at = :stale WHERE team_id = :team',
            ['stale' => $stale, 'team' => $teamId],
        );
        $connection->executeStatement(
            'UPDATE team_ai_usage SET period_ends_at = :stale WHERE team_id = :team',
            ['stale' => $stale, 'team' => $teamId],
        );

        $exit = $this->tester()->execute([]);

        self::assertSame(0, $exit);
        self::assertSame(0, $enforcement->getMonthlyReportCount($teamId));
        self::assertSame(0, $enforcement->getOnDemandAiUsage($teamId));
    }

    public function testLeavesCurrentPeriodCountersUntouched(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);
        $teamId = $team->id->toString();

        $enforcement->incrementMonthlyReportCount($teamId);
        $enforcement->incrementOnDemandAiUsage($teamId);

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No usage counters to reset.', $tester->getDisplay());
        self::assertSame(1, $enforcement->getMonthlyReportCount($teamId));
        self::assertSame(1, $enforcement->getOnDemandAiUsage($teamId));
    }

    private function createTeam(EntityManagerInterface $em): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Reset Test',
            slug: 'reset-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:usage:reset'));
    }
}
