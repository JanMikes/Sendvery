<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Query\GetTeamPlan;
use App\Repository\TeamInvitationRepository;
use App\Results\GlobalAddLimits;
use App\Services\DashboardContext;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use App\Value\TeamRole;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the `global_add_limits` Twig global so the top-bar "+ Add" dropdown
 * (rendered as the default `header_actions` block in `dashboard/layout.html.twig`)
 * can decide which menu items to enable / disable / hide without every
 * dashboard controller passing the data.
 *
 * Like {@see QuarantineCountExtension}, the resolver is wrapped in a defensive
 * try/catch: the layout is also rendered on unauth / pre-onboarding pages
 * where `DashboardContext` throws (no user, no memberships). We return the
 * all-permissive null state in those cases — the dropdown still won't actually
 * render because the layout's `{% if app.user %}` gate hides it upstream, but
 * a safe default keeps the Twig render from blowing up.
 */
final class GlobalAddDropdownExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly DashboardContext $dashboardContext,
        private readonly PlanEnforcement $planEnforcement,
        private readonly PlanLimits $planLimits,
        private readonly GetTeamPlan $getTeamPlan,
        private readonly TeamInvitationRepository $invitationRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'global_add_limits' => $this->resolveForActiveTeam(),
        ];
    }

    private function resolveForActiveTeam(): GlobalAddLimits
    {
        if (!$this->security->getUser() instanceof User) {
            return GlobalAddLimits::null();
        }

        try {
            $membership = $this->dashboardContext->getActiveMembership();
            $teamId = $membership->team->id;
            $teamIdString = $teamId->toString();

            $plan = $this->getTeamPlan->forTeam($teamIdString);

            $domainCount = $this->planEnforcement->getDomainCount($teamIdString);
            $maxDomains = $this->planLimits->getMaxDomains($plan);
            $canAddDomain = $domainCount < $maxDomains;

            $memberCount = $this->planEnforcement->getTeamMemberCount($teamIdString);
            $pendingCount = count($this->invitationRepository->findPendingForTeam($teamId));
            $effectiveMemberCount = $memberCount + $pendingCount;
            $maxMembers = $this->planLimits->getMaxTeamMembers($plan);
            $canAddTeamMember = $effectiveMemberCount < $maxMembers;

            $isTeamManager = in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true);

            return new GlobalAddLimits(
                canAddDomain: $canAddDomain,
                domainCount: $domainCount,
                maxDomains: $maxDomains,
                canAddMailbox: true,
                isTeamManager: $isTeamManager,
                canAddTeamMember: $canAddTeamMember,
                effectiveMemberCount: $effectiveMemberCount,
                maxMembers: $maxMembers,
            );
        } catch (\RuntimeException) {
            return GlobalAddLimits::null();
        }
    }
}
