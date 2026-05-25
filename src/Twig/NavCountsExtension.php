<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Query\GetAlerts;
use App\Query\GetDomainOverview;
use App\Query\GetQuarantineList;
use App\Services\DashboardContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Single Twig extension that exposes every "N waiting" badge count surfaced
 * by the dashboard sidebar — quarantine pile-up, unread alerts (overall +
 * critical-only), and unverified domains.
 *
 * Why one extension instead of four? With one extension per badge, every
 * authenticated page render issues FOUR small SELECT COUNT(*) queries via
 * FOUR separate Twig extensions, each re-resolving the active team and
 * re-checking the security context. Cheap individually, wasteful collectively,
 * and grows linearly with every new badge we add.
 *
 * Consolidating keeps the templates' API identical (each badge still reads
 * its own well-named global) while collapsing the security/team-resolve
 * overhead to a single pass per request.
 *
 * The counts are wrapped in a defensive try/catch: the layout is also rendered
 * on unauth/onboarding pages where {@see DashboardContext} throws (no user,
 * no memberships). We treat those as "0" for every count instead of
 * hard-failing the page.
 */
final class NavCountsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly DashboardContext $dashboardContext,
        private readonly GetAlerts $getAlerts,
        private readonly GetQuarantineList $getQuarantineList,
        private readonly GetDomainOverview $getDomainOverview,
    ) {
    }

    /** @return array<string, int> */
    public function getGlobals(): array
    {
        $teamId = $this->resolveTeamId();

        if (null === $teamId) {
            return [
                'quarantine_count' => 0,
                'unread_alert_count' => 0,
                'critical_alert_count' => 0,
                'unverified_domain_count' => 0,
            ];
        }

        $teamIdString = $teamId;

        return [
            'quarantine_count' => $this->getQuarantineList->countForTeam($teamIdString),
            'unread_alert_count' => $this->getAlerts->countUnreadForTeams([$teamIdString]),
            'critical_alert_count' => $this->getAlerts->countUnreadCriticalForTeams([$teamIdString]),
            'unverified_domain_count' => $this->getDomainOverview->countUnverifiedForTeams([$teamIdString]),
        ];
    }

    /**
     * Returns the active team UUID as a string, or `null` when the request
     * has no authenticated user / no team memberships. A single resolve per
     * request is the whole point of consolidating the badge extensions.
     */
    private function resolveTeamId(): ?string
    {
        if (!$this->security->getUser() instanceof User) {
            return null;
        }

        try {
            return $this->dashboardContext->getTeamId()->toString();
        } catch (\RuntimeException) {
            return null;
        }
    }
}
