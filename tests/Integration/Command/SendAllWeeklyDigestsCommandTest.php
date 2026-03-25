<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SendAllWeeklyDigestsCommandTest extends IntegrationTestCase
{
    #[Test]
    public function runsWithNoTeams(): void
    {
        self::bootKernel();
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:digest:send-all');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dispatching weekly digest', $tester->getDisplay());
    }

    #[Test]
    public function dispatchesDigestsForActiveTeams(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'digest-cmd-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Digest CMD Team',
            slug: 'digest-cmd-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();
        $em->clear();

        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:digest:send-all');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('All weekly digests dispatched', $tester->getDisplay());
    }
}
