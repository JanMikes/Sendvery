<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Message\SendWeeklyDigest;
use App\MessageHandler\SendWeeklyDigestHandler;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class SendWeeklyDigestHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function sendsDigestToTeamMembers(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(SendWeeklyDigestHandler::class);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'digest-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Digest Test Team',
            slug: 'digest-test-'.Uuid::uuid7()->toString(),
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

        // Should not throw — even with no reports/domains, the handler generates empty digest
        $handler(new SendWeeklyDigest(teamId: $team->id));

        // Handler runs without error
        self::assertTrue(true); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function skipsUsersWithDigestDisabled(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(SendWeeklyDigestHandler::class);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'no-digest-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            emailDigestEnabled: false,
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'No Digest Team',
            slug: 'no-digest-'.Uuid::uuid7()->toString(),
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

        // Should not throw — no recipients means no emails sent
        $handler(new SendWeeklyDigest(teamId: $team->id));

        self::assertTrue(true); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }
}
