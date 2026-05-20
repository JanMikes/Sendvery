<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Team;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetTeamPlanCommandTest extends IntegrationTestCase
{
    #[Test]
    public function grantsUnlimitedPlanByUuid(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'unlim-uuid-'.Uuid::uuid7()->toString());

        $tester = $this->commandTester();
        $tester->execute([
            'team' => $team->id->toString(),
            'plan' => 'unlimited',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('unlimited', $reloaded->plan);
        self::assertNull($reloaded->planWarningAt);
    }

    #[Test]
    public function resolvesTeamBySlug(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $slug = 'set-plan-slug-'.Uuid::uuid7()->toString();
        $team = $this->createTeam($em, $slug);

        $tester = $this->commandTester();
        $tester->execute([
            'team' => $slug,
            'plan' => 'team',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('team', $reloaded->plan);
    }

    #[Test]
    public function clearsPlanWarningWhenChangingPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'clear-warning-'.Uuid::uuid7()->toString());
        $team->planWarningAt = new \DateTimeImmutable('2026-01-01 12:00:00');
        $em->flush();

        $tester = $this->commandTester();
        $tester->execute([
            'team' => $team->id->toString(),
            'plan' => 'unlimited',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->planWarningAt);
    }

    #[Test]
    public function failsOnUnknownPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'bad-plan-'.Uuid::uuid7()->toString());

        $tester = $this->commandTester();
        $tester->execute([
            'team' => $team->id->toString(),
            'plan' => 'enterprise',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown plan', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenTeamNotFound(): void
    {
        $tester = $this->commandTester();
        $tester->execute([
            'team' => 'no-such-team-anywhere',
            'plan' => 'unlimited',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenTeamUuidNotFound(): void
    {
        $tester = $this->commandTester();
        $tester->execute([
            'team' => Uuid::uuid7()->toString(),
            'plan' => 'unlimited',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        self::bootKernel();
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:team:set-plan');

        return new CommandTester($command);
    }

    private function createTeam(EntityManagerInterface $em, string $slug): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Set Plan Test',
            slug: $slug,
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }
}
