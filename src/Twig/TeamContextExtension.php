<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\TeamMembership;
use App\Entity\User;
use App\Services\DashboardContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Surfaces the user's active team + all their memberships to layout
 * templates so the sidebar can render the team-switcher chip without
 * every controller having to pass the same variables. Returns `null`
 * for anonymous users so the layout can still render (e.g. on the
 * auth-failed page rendered through a dashboard-shaped error template).
 */
final class TeamContextExtension extends AbstractExtension
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('active_membership', $this->activeMembership(...)),
            new TwigFunction('all_memberships', $this->allMemberships(...)),
        ];
    }

    public function activeMembership(): ?TeamMembership
    {
        if (!$this->security->getUser() instanceof User) {
            return null;
        }

        try {
            return $this->dashboardContext->getActiveMembership();
        } catch (\RuntimeException) {
            // User authenticated but no membership yet (onboarding state).
            return null;
        }
    }

    /**
     * @return list<TeamMembership>
     */
    public function allMemberships(): array
    {
        if (!$this->security->getUser() instanceof User) {
            return [];
        }

        try {
            return $this->dashboardContext->getAllMemberships();
        } catch (\RuntimeException) {
            return [];
        }
    }
}
