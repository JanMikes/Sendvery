<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class DashboardContext
{
    public function __construct(
        private Security $security,
        private TeamMembershipRepository $teamMembershipRepository,
    ) {
    }

    public function getTeamId(): UuidInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated user found. Dashboard requires authentication.');
        }

        $memberships = $this->teamMembershipRepository->findForUser($user->id);

        if ([] === $memberships) {
            throw new \RuntimeException('User has no team memberships.');
        }

        return $memberships[0]->team->id;
    }
}
