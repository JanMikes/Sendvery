<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SeedDemoDataCommand;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDemoDataCommandTest extends IntegrationTestCase
{
    #[Test]
    public function seedsDemoTeamWithExpectedRowCounts(): void
    {
        $tester = $this->commandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Demo Team', $tester->getDisplay());

        $counts = $this->countSeededRows();
        self::assertSame(1, $counts['team']);
        self::assertSame(3, $counts['domains']);
        self::assertSame(SeedDemoDataCommand::REPORTS_PER_DOMAIN * 3, $counts['reports']);
        self::assertSame(SeedDemoDataCommand::SNAPSHOTS_PER_DOMAIN * 3, $counts['snapshots']);
        self::assertSame(SeedDemoDataCommand::ALERT_COUNT, $counts['alerts']);
    }

    #[Test]
    public function isIdempotentAcrossMultipleRuns(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);
        $firstRunCounts = $this->countSeededRows();

        $tester->execute([]);
        $secondRunCounts = $this->countSeededRows();

        self::assertSame($firstRunCounts, $secondRunCounts);
    }

    #[Test]
    public function refusesToRunInProdEnvironment(): void
    {
        self::bootKernel();
        $command = new SeedDemoDataCommand(
            entityManager: $this->getService(EntityManagerInterface::class),
            identityProvider: $this->getService(IdentityProvider::class),
            clock: $this->getService(ClockInterface::class),
            environment: 'prod',
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('refuses to run', $tester->getDisplay());

        // And nothing was seeded.
        self::assertSame(0, $this->countSeededRows()['team']);
    }

    /**
     * @return array{team: int, domains: int, reports: int, snapshots: int, alerts: int}
     */
    private function countSeededRows(): array
    {
        $connection = $this->getService(Connection::class);
        $teamRow = $connection->fetchAssociative(
            'SELECT id FROM team WHERE slug = :slug',
            ['slug' => SeedDemoDataCommand::DEMO_TEAM_SLUG],
        );

        if (false === $teamRow) {
            return ['team' => 0, 'domains' => 0, 'reports' => 0, 'snapshots' => 0, 'alerts' => 0];
        }

        $teamId = $teamRow['id'];
        assert(is_string($teamId));

        $domains = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM monitored_domain WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );
        $reports = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM dmarc_report dr JOIN monitored_domain md ON md.id = dr.monitored_domain_id WHERE md.team_id = :teamId',
            ['teamId' => $teamId],
        );
        $snapshots = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM domain_health_snapshot dhs JOIN monitored_domain md ON md.id = dhs.monitored_domain_id WHERE md.team_id = :teamId',
            ['teamId' => $teamId],
        );
        $alerts = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM alert WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );

        return [
            'team' => 1,
            'domains' => $domains,
            'reports' => $reports,
            'snapshots' => $snapshots,
            'alerts' => $alerts,
        ];
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:demo:seed'));
    }
}
