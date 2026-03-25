<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Message\CreateTeam;
use App\Repository\UserRepository;
use App\Services\IdentityProvider;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[AsMessageHandler]
final readonly class CreateTeamHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateTeam $message): void
    {
        $owner = $this->userRepository->get($message->ownerUserId);
        $now = $this->clock->now();

        $slugger = new AsciiSlugger();
        $slug = $slugger->slug($message->name)->lower()->toString();

        $team = new Team(
            id: $message->teamId,
            name: $message->name,
            slug: $slug,
            createdAt: $now,
        );

        $membership = new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $owner,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: $now,
        );

        $this->entityManager->persist($team);
        $this->entityManager->persist($membership);
    }
}
