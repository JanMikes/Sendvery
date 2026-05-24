<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Query\GetDomainOverview;
use App\Services\DashboardContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the `unverified_domain_count` Twig global so the sidebar nav can
 * render a red badge next to the Domains link whenever the team has at least
 * one domain that hasn't passed DMARC DNS verification — the strongest signal
 * that the user needs to fix DNS before reports flow in.
 *
 * Attention-status (verified-but-failing) domains are intentionally NOT counted
 * here: DmarcPassRateRegressed alerts already drive the Alerts badge (TASK-060),
 * and double-signalling defeats the "single badge = look here" principle.
 *
 * The count is wrapped in a defensive try/catch: the layout is also rendered
 * on unauth/onboarding pages where DashboardContext throws (no user, no
 * memberships). We treat those as "0" instead of hard-failing the page.
 */
final class DomainHealthCountExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainOverview $getDomainOverview,
    ) {
    }

    /** @return array<string, int> */
    public function getGlobals(): array
    {
        return [
            'unverified_domain_count' => $this->resolveCount(),
        ];
    }

    private function resolveCount(): int
    {
        if (!$this->security->getUser() instanceof User) {
            return 0;
        }

        try {
            $teamId = $this->dashboardContext->getTeamId();
        } catch (\RuntimeException) {
            return 0;
        }

        return $this->getDomainOverview->countUnverifiedForTeams([$teamId->toString()]);
    }
}
