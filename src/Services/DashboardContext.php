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

    /**
     * The "active" team — the one mutations target by default (creating a
     * domain, inviting a teammate, etc.). Today this is just the first
     * membership; if/when we add a UI team switcher it becomes the picked one.
     *
     * Read queries should use {@see getTeamIds()} instead: a user with
     * multiple memberships must be able to read data from every team they've
     * joined, not only the active one.
     */
    public function getTeamId(): UuidInterface
    {
        $memberships = $this->loadMemberships();

        return $memberships[0]->team->id;
    }

    /**
     * Every team UUID the authenticated user is a member of. Used as the
     * scope for all read queries against tenant data — `WHERE team_id IN
     * (...)` lets the user see resources from every team they belong to and
     * blocks everything else.
     *
     * @return list<UuidInterface>
     */
    public function getTeamIds(): array
    {
        return array_values(array_map(
            static fn ($membership) => $membership->team->id,
            $this->loadMemberships(),
        ));
    }

    /**
     * Same as {@see getTeamIds()} but as strings — most of our raw SQL takes
     * string UUIDs, so this avoids casting at every call site.
     *
     * @return list<string>
     */
    public function getTeamIdStrings(): array
    {
        return array_values(array_map(
            static fn (UuidInterface $id) => $id->toString(),
            $this->getTeamIds(),
        ));
    }

    /** @return non-empty-list<\App\Entity\TeamMembership> */
    private function loadMemberships(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated user found. Dashboard requires authentication.');
        }

        $memberships = array_values($this->teamMembershipRepository->findForUser($user->id));

        if ([] === $memberships) {
            throw new \RuntimeException('User has no team memberships.');
        }

        return $memberships;
    }
}
