<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Message\CreateTeam;
use App\MessageHandler\CreateTeamHandler;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CreateTeamHandlerTest extends IntegrationTestCase
{
    public function testCreatesTeamAndOwnerMembership(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'owner-'.$userId->toString().'@test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($user);
        $em->flush();
        $em->clear();

        $teamId = Uuid::uuid7();
        $command = new CreateTeam(
            teamId: $teamId,
            name: 'New Team',
            ownerUserId: $userId,
        );

        $handler = self::getContainer()->get(CreateTeamHandler::class);
        assert($handler instanceof CreateTeamHandler);
        $handler($command);
        $em->flush();

        $team = $em->find(Team::class, $teamId);
        self::assertNotNull($team);
        self::assertSame('New Team', $team->name);
        self::assertSame('new-team', $team->slug);

        $memberships = $em->getRepository(TeamMembership::class)->findBy(['team' => $teamId->toString()]);
        self::assertCount(1, $memberships);
        self::assertSame(TeamRole::Owner, $memberships[0]->role);
        self::assertSame($userId->toString(), $memberships[0]->user->id->toString());
    }
}
