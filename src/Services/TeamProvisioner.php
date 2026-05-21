<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class TeamProvisioner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamMembershipRepository $teamMembershipRepository,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Idempotently ensure the user owns a team and return it. Creates a team
     * named after the user's email domain when none exists (mirroring what
     * MagicLinkAuthenticator does at sign-up) — onboarding flows can't assume
     * that the sign-up path always seeds one.
     */
    public function provisionForUser(User $user): Team
    {
        $memberships = $this->teamMembershipRepository->findForUser($user->id);

        if ([] !== $memberships) {
            return $memberships[0]->team;
        }

        $now = $this->clock->now();
        $domain = $this->extractEmailDomain($user->email);
        $slug = (new AsciiSlugger())->slug($domain)->lower()->toString().'-'.substr($user->id->toString(), 0, 8);

        $team = new Team(
            id: $this->identityProvider->nextIdentity(),
            name: $domain,
            slug: $slug,
            createdAt: $now,
        );
        $this->entityManager->persist($team);

        $membership = new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: $now,
        );
        $this->entityManager->persist($membership);

        $this->entityManager->flush();

        return $team;
    }

    private function extractEmailDomain(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? 'personal';
    }
}
