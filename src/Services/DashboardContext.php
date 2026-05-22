<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DashboardContext
{
    /**
     * Session key holding the currently active team UUID (string). The
     * session-backed value is always validated against the user's actual
     * memberships before being trusted — stale or tampered values fall back
     * to the user's first membership rather than ever exposing another
     * tenant's data.
     */
    public const string SESSION_KEY = 'active_team_id';

    public function __construct(
        private Security $security,
        private TeamMembershipRepository $teamMembershipRepository,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * The "active" team — the one mutations target by default and that
     * team-specific pages (settings, billing, invite, add-domain) operate on.
     * Persisted in the session via the sidebar team switcher. Falls back to
     * the user's first membership when no active team is set or the stored
     * value is no longer valid.
     */
    public function getTeamId(): UuidInterface
    {
        return $this->getActiveMembership()->team->id;
    }

    public function getActiveMembership(): TeamMembership
    {
        $memberships = $this->loadMemberships();
        $sessionTeamId = $this->getStoredActiveTeamId();

        if (null !== $sessionTeamId) {
            foreach ($memberships as $membership) {
                if ($membership->team->id->equals($sessionTeamId)) {
                    return $membership;
                }
            }
        }

        return $memberships[0];
    }

    /**
     * Persist a new active team. The team MUST be one the user is a member
     * of — otherwise we throw rather than silently ignore, so the caller
     * (the switcher controller) can surface a 404 instead of pretending the
     * switch succeeded.
     */
    public function setActiveTeam(UuidInterface $teamId): void
    {
        foreach ($this->loadMemberships() as $membership) {
            if ($membership->team->id->equals($teamId)) {
                $this->requestStack->getSession()->set(self::SESSION_KEY, $teamId->toString());

                return;
            }
        }

        throw new \DomainException(sprintf('User is not a member of team "%s" — cannot make it active.', $teamId->toString()));
    }

    /**
     * Every team UUID the authenticated user is a member of. Used as the
     * scope for read queries against tenant data — `WHERE team_id IN (...)`
     * lets the user see resources from every team they belong to and blocks
     * everything else. The order matches the user's membership list (the
     * active team is included like any other).
     *
     * @return list<UuidInterface>
     */
    public function getTeamIds(): array
    {
        return array_values(array_map(
            static fn (TeamMembership $membership) => $membership->team->id,
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

    /**
     * Every team the user belongs to, surfaced for the sidebar switcher
     * dropdown. Returned in stable name-then-slug order so the dropdown
     * doesn't reshuffle between requests.
     *
     * @return list<TeamMembership>
     */
    public function getAllMemberships(): array
    {
        $memberships = $this->loadMemberships();

        usort($memberships, static function (TeamMembership $a, TeamMembership $b): int {
            $cmp = strcasecmp($a->team->name, $b->team->name);

            return 0 !== $cmp ? $cmp : strcmp($a->team->slug, $b->team->slug);
        });

        return $memberships;
    }

    private function getStoredActiveTeamId(): ?UuidInterface
    {
        if (!$this->requestStack->getMainRequest()?->hasSession()) {
            return null;
        }

        $stored = $this->requestStack->getSession()->get(self::SESSION_KEY);

        if (!is_string($stored) || '' === $stored) {
            return null;
        }

        try {
            return Uuid::fromString($stored);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return non-empty-list<TeamMembership> */
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
